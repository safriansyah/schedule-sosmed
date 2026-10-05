<?php

namespace App\Services\Students;

use App\Enums\Priority;
use App\Enums\StudentCondition;
use App\Enums\TicketFlag;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Models\Student;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Tickets\TicketNumberFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Turns imported students into tickets, in bulk.
 *
 * "Generate Ticket" on the hand-out screen raises one ticket per student so
 * every case has a place to be worked and followed up, instead of the
 * spreadsheet row being the only record. There is no "how many" — the button
 * acts on the whole list the admin is looking at.
 *
 * Two properties this class has to hold:
 *
 *   Idempotent. A student who already has a generated ticket is skipped, so
 *   pressing the button twice does not hand every operator a duplicate queue.
 *   The skip is a NOT EXISTS in the same statement that selects the batch, not
 *   a check in PHP, so two admins clicking together cannot both pass it.
 *
 *   Fast enough to run in a request. 7.400 students through the ordinary
 *   create() path is one SELECT MAX + one INSERT each — tens of thousands of
 *   round trips. Numbers are allocated once per batch and the rows go in with
 *   a bulk insert instead.
 */
class StudentTicketGenerator
{
    /** Rows per insert. Large enough to be quick, small enough for max_allowed_packet. */
    private const CHUNK = 250;

    public function __construct(
        private readonly ActivityLogger $log,
        private readonly TicketNumberFormatter $numbers,
        private readonly StudentStats $stats,
    ) {}

    /**
     * How many students in this filter would get a ticket — the number the
     * button shows, so the admin is never told 350 and handed a different 350.
     *
     * @param  array<string, mixed>  $filters
     */
    public function countPending(array $filters): int
    {
        return $this->pending($filters)->count();
    }

    /**
     * Raise a ticket for every student in the filter that has none.
     *
     * $assignee: hand every new ticket to this operator ("Buat Ticket per
     * Wilayah"). Left null, a ticket follows whoever holds the student.
     *
     * @param  array<string, mixed>  $filters
     * @return array{created: int, skipped: int}
     */
    public function generate(array $filters, User $actor, ?User $assignee = null): array
    {
        $categories = $this->categoryMap();
        $created = 0;

        // Re-query each round rather than paginating: the rows just handled no
        // longer match `pending`, so the next batch is always the next 250
        // outstanding students — no offset to drift.
        while (true) {
            $students = $this->pending($filters)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get([
                    'id', 'nim', 'nac', 'nama', 'email', 'no_hp', 'no_hp_raw',
                    'kategori_masalah', 'kategori_masalah_raw', 'assigned_to',
                ]);

            if ($students->isEmpty()) {
                break;
            }

            $created += $this->insertBatch($students, $categories, $actor, $assignee);
        }

        if ($created > 0) {
            $this->log->log(
                'student.tickets_generated',
                "Generate {$created} tiket dari data mahasiswa",
                null,
                ['count' => $created, 'filters' => array_filter($filters)],
            );

            $this->stats->forgetOptions();
        }

        return ['created' => $created, 'skipped' => $this->countWithTicket($filters)];
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    /**
     * Students in this filter that do not have a ticket yet.
     *
     * @param  array<string, mixed>  $filters
     */
    private function pending(array $filters): Builder
    {
        return Student::query()
            ->filtered($filters)
            // Picked by hand on /students/unsigned.
            ->when(isset($filters['ids']), fn (Builder $q) => $q->whereIn('id', (array) $filters['ids']))
            ->whereNotNull('nim')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('tickets')
                    ->whereColumn('tickets.student_id', 'students.id')
                    ->whereNull('tickets.deleted_at');
            });
    }

    /** @param  array<string, mixed>  $filters */
    private function countWithTicket(array $filters): int
    {
        return Student::query()
            ->filtered($filters)
            ->when(isset($filters['ids']), fn (Builder $q) => $q->whereIn('id', (array) $filters['ids']))
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('tickets')
                    ->whereColumn('tickets.student_id', 'students.id')
                    ->whereNull('tickets.deleted_at');
            })
            ->count();
    }

    /**
     * Write one batch: tickets, then the NIM rows that link them back.
     *
     * @param  \Illuminate\Support\Collection<int, Student>  $students
     * @param  array<string, array{0: int|null, 1: int|null}>  $categories
     */
    private function insertBatch($students, array $categories, User $actor, ?User $assignee = null): int
    {
        return DB::transaction(function () use ($students, $categories, $actor, $assignee) {
            // One number lookup for the whole batch. `next()` gives the first;
            // the rest follow it, and the UNIQUE index is still the thing that
            // guarantees no duplicate ever lands.
            //
            // Tickets raised in bulk use the DEFAULT format — nobody is at a
            // screen choosing TKU or TKB for 7.400 rows one at a time.
            $format = $this->numbers->defaultFormat();
            $pattern = $format['pattern'];
            $sequence = $this->sequenceOf($this->numbers->next($format['code']));

            $now = now();
            $rows = [];
            $nims = [];

            foreach ($students as $index => $student) {
                $condition = $student->kategori_masalah instanceof StudentCondition
                    ? $student->kategori_masalah
                    : StudentCondition::tryFrom((string) $student->kategori_masalah);

                [$categoryId, $subCategoryId] = $categories[$condition?->value ?? ''] ?? [null, null];

                $rows[] = [
                    'number' => $this->numbers->preview($pattern, $sequence + $index),
                    'source' => TicketSource::StudentImport->value,
                    'subject' => $this->subjectFor($student, $condition),
                    'description' => $this->descriptionFor($student, $condition),
                    'category_id' => $categoryId,
                    'sub_category_id' => $subCategoryId,
                    // The operator chosen for the region, or else whoever
                    // already holds the student — re-distributing by hand
                    // would be busywork.
                    'status' => ($assignee?->id ?? $student->assigned_to)
                        ? TicketStatus::Assigned->value
                        : TicketStatus::Open->value,
                    'priority' => ($condition?->priority() ?? Priority::Normal)->value,
                    'flag' => TicketFlag::Netral->value,
                    'student_id' => $student->id,
                    'requester_name' => $student->nama,
                    'requester_nim' => $student->nim,
                    'requester_nac' => $student->nac,
                    'requester_phone' => $student->no_hp_raw ?: $student->no_hp,
                    'requester_email' => $student->email,
                    'assigned_to' => $assignee?->id ?? $student->assigned_to,
                    'assigned_at' => ($assignee?->id ?? $student->assigned_to) ? $now : null,
                    'created_by' => $actor->id,
                    'extra' => json_encode(['generated_from_import' => true]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $nims[$student->nim] = $student->id;
            }

            Ticket::insert($rows);

            $this->insertDetails(array_column($rows, 'number'), $nims, $actor, $now);

            // Tickets handed to a chosen operator take the students with them,
            // so /students shows them as that operator's, not "belum assigned".
            if ($assignee) {
                Student::followTicketAssignee($students->pluck('id')->all(), $assignee, $actor);
            }

            return count($rows);
        });
    }

    /**
     * The TiketDetail row for each new ticket — the "Data Mahasiswa (1)" panel
     * on the ticket page reads this, and it is what lets a ticket cover more
     * than one NIM later on.
     *
     * @param  array<int, string>  $numbers
     * @param  array<string, int>  $nims  nim => student id
     */
    private function insertDetails(array $numbers, array $nims, User $actor, $now): void
    {
        // The ids were assigned by the insert above, so they are read back by
        // the numbers just written rather than guessed from an auto-increment.
        $tickets = Ticket::whereIn('number', $numbers)->pluck('requester_nim', 'id');

        $details = [];

        foreach ($tickets as $ticketId => $nim) {
            if (! isset($nims[$nim])) {
                continue;
            }

            $details[] = [
                'ticket_id' => $ticketId,
                'nim' => $nim,
                'student_id' => $nims[$nim],
                'created_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($details !== []) {
            DB::table('ticket_details')->insert($details);
        }
    }

    private function subjectFor(Student $student, ?StudentCondition $condition): string
    {
        $label = $condition?->label() ?? 'Perlu tindak lanjut';
        $name = $student->nama ?: $student->nim;

        return mb_substr("{$label} — {$name}", 0, 200);
    }

    private function descriptionFor(Student $student, ?StudentCondition $condition): string
    {
        $raw = $student->kategori_masalah_raw;

        return trim(sprintf(
            "Dibuat otomatis dari data mahasiswa.\nNIM: %s\nKondisi: %s",
            $student->nim,
            $raw ?: ($condition?->label() ?? 'tidak diketahui'),
        ));
    }

    /** The trailing digits of a rendered number, so a batch can count on from it. */
    private function sequenceOf(string $number): int
    {
        return preg_match('/(\d+)(?!.*\d)/', $number, $m) === 1 ? (int) $m[1] : 1;
    }

    /**
     * Student condition → ticket category, resolved once per run.
     *
     * A generated ticket that lands in no category makes the ticket list's
     * category filter useless for exactly the 7.000 rows that need it most.
     *
     * @return array<string, array{0: int|null, 1: int|null}>
     */
    private function categoryMap(): array
    {
        $roots = TicketCategory::roots()->with('children')->get();

        $find = function (string $parent, ?string $child) use ($roots) {
            $root = $roots->firstWhere('name', $parent);

            if ($root === null) {
                return [null, null];
            }

            return [$root->id, $child ? $root->children->firstWhere('name', $child)?->id : null];
        };

        return [
            StudentCondition::NonAktifDn->value => $find('Registrasi', 'Belum registrasi'),
            StudentCondition::OngoingTidakRegistrasi->value => $find('Registrasi', 'Belum registrasi'),
            StudentCondition::OngoingBillingPending->value => $find('Pembayaran', 'Billing NAC belum bayar'),
            StudentCondition::AdmisiTidakBayar->value => $find('Pembayaran', 'Sudah registrasi belum bayar'),
            StudentCondition::AdmisiKurangBerkas->value => $find('Registrasi', 'Gagal registrasi sistem'),
            StudentCondition::AdmisiGagalValidasi->value => $find('Registrasi', 'Gagal registrasi sistem'),
            StudentCondition::MabaBelumBayarMk->value => $find('Pembayaran', 'Sudah registrasi belum bayar'),
            StudentCondition::MabaBelumRegMk->value => $find('Registrasi', 'Belum registrasi mata kuliah'),
            StudentCondition::Other->value => $find('Lainnya', null),
        ];
    }
}
