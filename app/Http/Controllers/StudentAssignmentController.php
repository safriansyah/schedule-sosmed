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
     * "Assign Wilayah & Ticket": every unassigned student in a region goes to
     * one operator, and in the same step each of that operator's students in
     * the region gets a ticket, already in the operator's name.
     *
     * Both an operator and a region are required: there is no "everything"
     * button any more, so a ticket is never raised without someone to work
     * it. The admin narrows as far as they like — a whole kabupaten, a
     * kecamatan, a Pokjar/SALUT — and there is no count field, by design.
     */
    public function byRegion(Request $request, StudentTicketGenerator $generator): RedirectResponse
    {
        $this->authorize(Permission::AssignStudents->value);

        [$region, $operator] = $this->regionAndOperator($request, 'operator_id');

        if ($region === null) {
            return back()->withErrors([
                'kabupaten' => 'Pilih minimal satu tingkat wilayah (Kabupaten, Kecamatan, Pokjar/SALUT, …). Tanpa itu seluruh mahasiswa akan ikut terbagikan.',
            ])->withInput();
        }

        $result = $this->assigner->assignByRegion($region, $operator, $request->user());

        // Tickets for this operator's students in the region that have none
        // yet — the ones just assigned, and any they already held there.
        $tickets = $request->user()->hasPermission(Permission::CreateTickets)
            ? $generator->generate($region + ['operator' => (string) $operator->id], $request->user())['created']
            : 0;

        if ($result['assigned'] === 0 && $tickets === 0) {
            return back()->withErrors([
                'kabupaten' => $result['matched'] > 0
                    ? "Semua {$result['matched']} mahasiswa di wilayah itu sudah dipegang operator lain, dan tiket {$operator->name} di wilayah ini sudah lengkap."
                    : 'Tidak ada mahasiswa di wilayah itu.',
            ])->withInput();
        }

        $where = $this->describe($region);
        $message = "{$result['assigned']} mahasiswa wilayah {$where} ditugaskan ke {$operator->name}";
        $message .= $tickets > 0 ? ", {$tickets} tiket dibuat." : '.';

        // The gap between matched and assigned is how the admin learns part of
        // the region was already someone else's.
        if ($result['matched'] > $result['assigned']) {
            $held = $result['matched'] - $result['assigned'];
            $message .= " {$held} dilewati karena sudah dipegang operator (pakai Pindah Operator bila salah).";
        }

        return back()->with('success', $message);
    }

    /**
     * "Pindah Operator": a region handed to the wrong person goes to the
     * right one — students and their open tickets together.
     */
    public function move(Request $request): RedirectResponse
    {
        $this->authorize(Permission::AssignStudents->value);

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

        if ($result['students'] === 0) {
            return back()->withErrors([
                'move' => "{$from->name} tidak memegang mahasiswa di wilayah {$this->describe($region)}.",
            ])->withInput();
        }

        return back()->with('success', sprintf(
            '%d mahasiswa dan %d tiket wilayah %s dipindah dari %s ke %s.',
            $result['students'],
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
