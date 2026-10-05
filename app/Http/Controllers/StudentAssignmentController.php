<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\User;
use App\Services\Students\StudentAssigner;
use App\Services\Students\StudentStats;
use App\Services\Students\StudentTicketGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Handing students out to operators, two ways: by region, and by ticking rows.
 *
 * "Give N students to this operator" used to be here too and was removed: a
 * headcount splits a village between two operators, which makes the work
 * impossible to plan and the names impossible to recognise. The region IS the
 * batch now.
 *
 * Both paths delegate the actual writing to StudentAssigner, which is where
 * the "never assigned twice" guarantee lives.
 */
class StudentAssignmentController extends Controller
{
    public function __construct(private readonly StudentAssigner $assigner) {}

    /** Manual select: the ticked rows go to one operator. */
    public function selected(Request $request): RedirectResponse
    {
        $this->authorize(Permission::AssignStudents->value);

        $data = $request->validate([
            'students' => ['required', 'array', 'min:1'],
            'students.*' => ['integer'],
            'operator_id' => ['required', 'integer', User::ticketHandlerRule()],
            'reassign' => ['nullable', 'boolean'],
        ], [], [
            'students' => 'mahasiswa',
            'operator_id' => 'operator',
        ]);

        $operator = User::findOrFail($data['operator_id']);

        $result = $this->assigner->assignSelected(
            $data['students'],
            $operator,
            $request->user(),
            (bool) ($data['reassign'] ?? false),
        );

        // The skipped count is not noise — it is how the admin learns that
        // some of what they ticked was already someone else's.
        $message = "{$result['assigned']} mahasiswa ditugaskan ke {$operator->name}.";

        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} dilewati karena sudah dipegang operator lain.";
        }

        return back()->with('success', $message);
    }

    /**
     * "Buat Ticket per Wilayah": every student in the chosen region who has no
     * ticket yet gets one, straight in the chosen operator's name. No
     * assigning first — whether a student was "assigned" before does not
     * matter, so a region where everyone is already held still works.
     *
     * Both an operator and a region are required: there is no "everything"
     * button, so a ticket is never raised without someone to work it.
     */
    public function byRegion(Request $request, StudentTicketGenerator $generator): RedirectResponse
    {
        $this->authorize(Permission::CreateTickets->value);
        $this->authorize(Permission::AssignTickets->value);

        [$region, $operator] = $this->regionAndOperator($request, 'operator_id');

        if ($region === null) {
            return back()->withErrors([
                'kabupaten' => 'Pilih minimal satu tingkat wilayah (Kabupaten, Kecamatan, Pokjar/SALUT, …). Tanpa itu seluruh mahasiswa akan ikut dibuatkan tiket.',
            ])->withInput();
        }

        $result = $generator->generate($region, $request->user(), $operator);
        $where = $this->describe($region);

        if ($result['created'] === 0) {
            return back()->withErrors([
                'kabupaten' => $result['skipped'] > 0
                    ? "Semua {$result['skipped']} mahasiswa wilayah {$where} sudah punya tiket. Pakai Pindah Ticket bila operatornya salah."
                    : "Tidak ada mahasiswa di wilayah {$where}.",
            ])->withInput();
        }

        $message = "{$result['created']} tiket mahasiswa wilayah {$where} dibuat untuk {$operator->name}.";

        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} mahasiswa dilewati karena sudah punya tiket.";
        }

        return back()->with('success', $message);
    }

    /** "Buat Ticket Terpilih": the students ticked in the list, to one operator. */
    public function selectedTickets(Request $request, StudentTicketGenerator $generator): RedirectResponse
    {
        $this->authorize(Permission::CreateTickets->value);
        $this->authorize(Permission::AssignTickets->value);

        $data = $request->validate([
            'students' => ['required', 'array', 'min:1'],
            'students.*' => ['integer'],
            'operator_id' => ['required', 'integer', User::ticketHandlerRule()],
        ], [], [
            'students' => 'mahasiswa',
            'operator_id' => 'operator',
        ]);

        $operator = User::findOrFail($data['operator_id']);
        $ids = array_values(array_unique(array_map('intval', $data['students'])));

        $result = $generator->generate(['ids' => $ids], $request->user(), $operator);

        if ($result['created'] === 0) {
            return back()->withErrors(['students' => 'Mahasiswa yang dipilih sudah punya tiket.']);
        }

        return back()->with('success', "{$result['created']} tiket dibuat untuk {$operator->name}.");
    }

    /**
     * "Pindah Ticket": a region given to the wrong operator goes to the right
     * one — that operator's open tickets there, and any students assigned to
     * them there the old way.
     */
    public function move(Request $request): RedirectResponse
    {
        // Moving tickets between operators is assigning them.
        $this->authorize(Permission::AssignTickets->value);

        $request->validate([
            'from_operator_id' => ['required', 'integer', 'exists:users,id'],
            'to_operator_id' => ['required', 'integer', 'different:from_operator_id', User::ticketHandlerRule()],
        ], [
            'to_operator_id.different' => 'Operator tujuan harus berbeda dari operator asal.',
        ], [
            'from_operator_id' => 'operator asal',
            'to_operator_id' => 'operator tujuan',
        ]);

        [$region, $to] = $this->regionAndOperator($request, 'to_operator_id');
        $from = User::findOrFail($request->integer('from_operator_id'));

        if ($region === null) {
            return back()->withErrors([
                'move' => 'Pilih wilayah yang ingin dipindahkan di filter atas.',
            ])->withInput();
        }

        $result = $this->assigner->moveRegion($region, $from, $to, $request->user());

        if ($result['tickets'] === 0 && $result['students'] === 0) {
            return back()->withErrors([
                'move' => "{$from->name} tidak memegang tiket terbuka di wilayah {$this->describe($region)}.",
            ])->withInput();
        }

        return back()->with('success', sprintf(
            '%d tiket wilayah %s dipindah dari %s ke %s.',
            $result['tickets'],
            $this->describe($region),
            $from->name,
            $to->name,
        ));
    }

    /**
     * The region levels from the request (null when none is chosen) and the
     * operator named by $field, validated as a ticket handler.
     *
     * @return array{0: array<string, string>|null, 1: User}
     */
    private function regionAndOperator(Request $request, string $field): array
    {
        $rules = [$field => ['required', 'integer', User::ticketHandlerRule()]];

        foreach (StudentStats::REGION_LEVELS as $level) {
            $rules[$level] = ['nullable', 'string', 'max:128'];
        }

        $data = $request->validate($rules, [], [
            $field => 'operator',
        ] + StudentStats::regionLabels());

        $region = array_filter(
            array_intersect_key($data, array_flip(StudentStats::REGION_LEVELS)),
            fn ($v) => filled($v),
        );

        return [$region === [] ? null : $region, User::findOrFail($data[$field])];
    }

    /** @param  array<string, string>  $region */
    private function describe(array $region): string
    {
        return implode(' › ', $region);
    }

    /** Return students to the pool. */
    public function release(Request $request): RedirectResponse
    {
        $this->authorize(Permission::AssignStudents->value);

        $data = $request->validate([
            'students' => ['required', 'array', 'min:1'],
            'students.*' => ['integer'],
        ], [], ['students' => 'mahasiswa']);

        $count = $this->assigner->unassign($data['students'], $request->user());

        return back()->with('success', "{$count} mahasiswa dikembalikan ke daftar belum assigned.");
    }

    /**
     * The filter the admin had on screen when they pressed the button.
     *
     * Read from the request body, not the query string, so the action and the
     * count shown above it act on exactly the same set.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->only(
            'q', 'kondisi', 'semester', 'segmen', 'status_dp', 'import',
            ...StudentStats::REGION_LEVELS,
        );
    }
}
