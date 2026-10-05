<?php

namespace App\Http\Controllers;

use App\Enums\AssignmentStatus;
use App\Enums\Permission;
use App\Enums\StudentCondition;
use App\Models\Student;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Export\Exporter;
use App\Services\Students\StudentStats;
use App\Support\PhoneNumber;
use App\Services\Students\StudentTicketGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The student database: who is on the list, and who is working them.
 *
 * Holds PII, so every read is permission-gated AND scoped — an operator's
 * list is restricted in the query by Student::scopeVisibleTo(), not by hiding
 * links, so a guessed URL returns their own rows and nothing else.
 */
class StudentController extends Controller
{
    /**
     * Rows per page the user may ask for.
     *
     * An allowlist, not a free number: `?per_page=100000` on a 7.391-row table
     * would render a page nobody can use and tie up the server doing it.
     */
    public const PER_PAGE = [25, 50, 100, 200, 500];

    public function __construct(
        private readonly ActivityLogger $log,
        private readonly StudentStats $stats,
        private readonly StudentTicketGenerator $generator,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize(Permission::ViewStudents->value);

        $user = $request->user();
        $filters = $this->filters($request);

        $students = Student::query()
            ->visibleTo($user)
            ->filtered($filters)
            ->with(['assignee:id,name'])
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 25))
            ->withQueryString();

        return view('students.index', [
            'students' => $students,
            'filters' => $filters,
            'stats' => $this->stats->summary($user),
            // The list keeps its two-level region picker (the kecamatan select
            // is loaded over fetch); the full five-level cascade belongs on
            // the hand-out screen, where it decides who gets what.
            'kabupaten' => $this->stats->regionOptions('kabupaten', $user),
            'kecamatan' => $this->stats->regionOptions('kecamatan', $user, $filters),
            'conditions' => StudentCondition::options(),
            'assignments' => AssignmentStatus::options(),
            'imports' => $this->importOptions(),
            'pokjarOptions' => $this->stats->pokjarOptions($user),
            'segmenOptions' => $this->stats->segmenOptions($user),
            'operators' => $this->operators(),
            'perPageOptions' => self::PER_PAGE,
            'perPage' => $this->perPage($request, 25),
            'canAssign' => $user->hasPermission(Permission::AssignStudents),
        ]);
    }

    /** The hand-out queue: everything nobody is working yet. */
    /**
     * Unsigned & Ticket — the hand-out screen.
     *
     * Two things happen here, and they are separate decisions made by
     * different people at different times:
     *
     *   Generate Ticket — raise a ticket for every student on the list, so the
     *   case has somewhere to live. No headcount: it acts on the whole filter.
     *
     *   Assign — give an operator every student in a region. Also no
     *   headcount; the region IS the batch.
     */
    public function unsigned(Request $request): View
    {
        $this->authorize(Permission::AssignStudents->value);

        $user = $request->user();
        $filters = $this->filters($request) + ['assignment' => AssignmentStatus::Unassigned->value];
        $filters['assignment'] = AssignmentStatus::Unassigned->value;

        $students = Student::query()
            ->filtered($filters)
            ->unassigned()
            // So each row can say whether a ticket already exists for it —
            // otherwise "Generate Ticket" is a number with no detail behind it.
            ->withCount('tickets')
            ->orderBy('id')
            ->paginate($this->perPage($request, 50))
            ->withQueryString();

        // The count the buttons act on — read off the paginator rather than
        // counted again. It is the identical query, and running it twice cost
        // 27 ms of the page's 89 ms of database time for no new information.
        $matching = $students->total();

        // Generating tickets is NOT limited to the unassigned pool: a student
        // an operator already holds needs a ticket just as much. So the
        // generator sees the same filter without that restriction, and the
        // screen says which number belongs to which button.
        $ticketFilters = Arr::except($filters, 'assignment');

        return view('students.unsigned', [
            'students' => $students,
            'matching' => $matching,
            'filters' => $filters,
            'regions' => $this->stats->regionTree($user, $filters),
            'regionLevels' => StudentStats::REGION_LEVELS,
            'regionLabels' => StudentStats::regionLabels(),
            'conditions' => StudentCondition::options(),
            'imports' => $this->importOptions(),
            'operators' => $this->operators(),
            'perPageOptions' => self::PER_PAGE,
            'perPage' => $this->perPage($request, 50),
            'workload' => $this->stats->workload(),
            'ticketFilters' => $ticketFilters,
            // How many students in this filter would actually get a ticket.
            'ticketPending' => $this->generator->countPending($ticketFilters),
            'ticketScope' => Student::query()->filtered($ticketFilters)->count(),
            'canGenerate' => $user->hasPermission(Permission::CreateTickets),
        ]);
    }

    /**
     * One student's record, with every ticket raised against them.
     *
     * Visibility is checked here as well as in the list query: an operator who
     * guesses a URL must not reach a student who is not theirs, and hiding the
     * link is not a permission check.
     */
    public function show(Request $request, Student $student): View
    {
        $this->authorize(Permission::ViewStudents->value);
        $this->guardVisibility($request->user(), $student);

        $student->load(['assignee:id,name', 'assigner:id,name', 'import:id,original_name,created_at']);

        return view('students.show', [
            'student' => $student,
            'tickets' => $student->tickets()
                ->with(['assignee:id,name', 'category:id,name'])
                ->orderByDesc('id')
                ->get(),
            'conditions' => StudentCondition::options(),
            'assignments' => AssignmentStatus::options(),
        ]);
    }

    /**
     * Correct a student's record.
     *
     * Only the keys actually submitted are written. A partial form — the
     * compact panel, an inline editor — must not blank the fields it does not
     * render, which is exactly what `$data[...] ?? null` across the board
     * would do.
     */
    public function update(Request $request, Student $student): RedirectResponse
    {
        $this->authorize(Permission::ManageStudents->value);
        $this->guardVisibility($request->user(), $student);

        $data = $request->validate([
            'nama' => ['nullable', 'string', 'max:255'],
            'nac' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'no_hp_raw' => ['nullable', 'string', 'max:64'],
            'fakultas' => ['nullable', 'string', 'max:255'],
            'program_studi' => ['nullable', 'string', 'max:255'],
            'semester_terakhir' => ['nullable', 'string', 'max:32'],
            'provinsi' => ['nullable', 'string', 'max:64'],
            'kabupaten' => ['nullable', 'string', 'max:128'],
            'kecamatan' => ['nullable', 'string', 'max:128'],
            'kelurahan' => ['nullable', 'string', 'max:128'],
            'kategori_masalah' => ['nullable', 'string', 'max:48'],
            'assignment_status' => ['nullable', 'string', 'max:32'],
            'catatan' => ['nullable', 'string', 'max:5000'],
        ], [], [
            'nama' => 'nama',
            'no_hp_raw' => 'nomor HP',
            'kategori_masalah' => 'kondisi',
            'assignment_status' => 'status penanganan',
        ]);

        // Present-but-empty still counts as a deliberate clearing; absent does
        // not. array_key_exists, not filled().
        $changes = [];

        foreach ($data as $key => $value) {
            if (! $request->has($key)) {
                continue;
            }

            $changes[$key] = $value;
        }

        if ($changes === []) {
            return back()->with('info', 'Tidak ada perubahan.');
        }

        // The normalised form is derived, never typed: the import writes
        // no_hp from no_hp_raw the same way (StudentRowMapper), and letting
        // the two drift apart would break search, which looks in both.
        if (array_key_exists('no_hp_raw', $changes)) {
            $changes['no_hp'] = PhoneNumber::normalize($changes['no_hp_raw']);
        }

        // Diffed BEFORE the write, against the model as it still stands.
        $diff = $this->changes($student, $changes);

        $student->fill($changes)->save();

        $this->log->log(
            'student.updated',
            "Memperbarui data mahasiswa {$student->nim}",
            $student,
            ['changes' => $diff],
        );

        // A newly typed kabupaten has to appear in the filter dropdowns now,
        // not in an hour when the cache expires.
        $this->stats->forgetOptions();

        return back()->with('success', 'Data mahasiswa diperbarui.');
    }

    public function kecamatan(Request $request): JsonResponse
    {
        $this->authorize(Permission::ViewStudents->value);

        return response()->json(
            $this->stats->kecamatanOptions($request->user(), $request->input('kabupaten'))
        );
    }

    /** Export the CURRENT filter, in the chosen format. */
    public function export(Request $request, Exporter $exporter)
    {
        $this->authorize(Permission::ExportData->value);

        $user = $request->user();
        $filters = $this->filters($request);

        $query = Student::query()
            ->visibleTo($user)
            ->filtered($filters)
            ->with('assignee:id,name');

        $this->log->log('student.exported', 'Export data mahasiswa', null, [
            'format' => $request->input('format', 'xlsx'),
            'filters' => array_filter($filters),
        ]);

        return $exporter->download(
            format: (string) $request->input('format', 'xlsx'),
            filename: 'mahasiswa',
            columns: [
                'nim' => 'NIM',
                'nac' => 'NAC',
                'nama' => 'Nama',
                'email' => 'Email',
                'no_hp' => 'No HP',
                'program_studi' => 'Program Studi',
                'fakultas' => 'Fakultas',
                'semester_terakhir' => 'Semester',
                'kabupaten' => 'Kabupaten',
                'kecamatan' => 'Kecamatan',
                'pokjar' => 'Pokjar',
                'segmen' => 'Segmen',
                'status_dp' => 'Status DP',
                'kondisi' => 'Kondisi',
                'petugas_nama' => 'Petugas (dari berkas)',
                'assignment_status' => 'Status Assignment',
                'operator' => 'Operator',
                'assigned_at' => 'Tanggal Assign',
                'catatan' => 'Catatan',
            ],
            query: $query,
            mapper: fn (Student $s) => [
                $s->nim, $s->nac, $s->nama, $s->email, $s->no_hp_raw ?: $s->no_hp,
                $s->program_studi, $s->fakultas, $s->semester_terakhir,
                $s->kabupaten, $s->kecamatan, $s->pokjar, $s->segmen, $s->status_dp,
                $s->kategori_masalah?->label(), $s->petugas_nama, $s->assignment_status?->label(),
                $s->assignee?->name, $s->assigned_at, $s->catatan,
            ],
        );
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    /** Rows per page, clamped to what the allowlist permits. */
    private function perPage(Request $request, int $default): int
    {
        $asked = (int) $request->input('per_page');

        return in_array($asked, self::PER_PAGE, true) ? $asked : $default;
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->only(
            'q', 'kondisi', 'assignment', 'semester', 'operator', 'segmen', 'status_dp', 'import',
            // Provinsi → Kabupaten → Kecamatan → Kelurahan → Pokjar.
            ...StudentStats::REGION_LEVELS,
        );
    }

    /**
     * 403 rather than 404 when an operator opens someone else's student: the
     * row exists, they simply may not see it, and pretending otherwise makes
     * the permission model harder to reason about.
     */
    private function guardVisibility(User $user, Student $student): void
    {
        if (! $user->hasPermission(Permission::ViewAllStudents) && $student->assigned_to !== $user->id) {
            abort(403, 'Mahasiswa ini bukan tanggung jawab Anda.');
        }
    }

    /**
     * The completed import batches, newest first, for the "asal data" filter.
     *
     * The institution uploads several different lists — admisi belum bayar,
     * admisi baru, non-aktif — and each is a separate piece of work. Without
     * this they all dissolve into one pool of 7.400 rows and there is no way
     * to hand out just the newest file.
     *
     * @return array<int, string>
     */
    private function importOptions(): array
    {
        return \App\Models\StudentImport::query()
            ->where('status', 'completed')
            ->latest('id')
            ->limit(30)
            ->get(['id', 'original_name', 'finished_at'])
            ->mapWithKeys(fn ($i) => [
                $i->id => ($i->original_name ?: 'Import #'.$i->id)
                    .($i->finished_at ? ' — '.$i->finished_at->translatedFormat('j M Y') : ''),
            ])
            ->all();
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function operators()
    {
        // The same list as the ticket assign form: handing out a student also
        // hands over their tickets, so both pickers must offer the same people.
        return User::ticketHandlerOptions();
    }
}
