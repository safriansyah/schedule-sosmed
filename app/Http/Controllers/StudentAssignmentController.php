<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\User;
use App\Services\Students\StudentAssigner;
use App\Services\Students\StudentStats;
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
            'operator_id' => ['required', 'integer', 'exists:users,id'],
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
     * Every unassigned student in a region → one operator.
     *
     * The admin narrows as far as they like: a whole kabupaten, or one
     * kelurahan. There is no count field, by design.
     */
    public function byRegion(Request $request): RedirectResponse
    {
        $this->authorize(Permission::AssignStudents->value);

        $rules = ['operator_id' => ['required', 'integer', 'exists:users,id']];

        foreach (StudentStats::REGION_LEVELS as $level) {
            $rules[$level] = ['nullable', 'string', 'max:128'];
        }

        $data = $request->validate($rules, [], [
            'operator_id' => 'operator',
        ] + StudentStats::regionLabels());

        $region = array_intersect_key($data, array_flip(StudentStats::REGION_LEVELS));

        if (array_filter($region, fn ($v) => filled($v)) === []) {
            return back()->withErrors([
                'kabupaten' => 'Pilih minimal satu tingkat wilayah. Tanpa itu seluruh mahasiswa akan ikut terbagikan.',
            ])->withInput();
        }

        $operator = User::findOrFail($data['operator_id']);

        $result = $this->assigner->assignByRegion($region, $operator, $request->user());

        if ($result['assigned'] === 0) {
            return back()->withErrors([
                'kabupaten' => $result['matched'] > 0
                    ? "Semua {$result['matched']} mahasiswa di wilayah itu sudah dipegang operator lain."
                    : 'Tidak ada mahasiswa di wilayah itu.',
            ])->withInput();
        }

        $where = implode(' › ', array_filter($region, fn ($v) => filled($v)));
        $message = "{$result['assigned']} mahasiswa wilayah {$where} ditugaskan ke {$operator->name}.";

        // The gap between matched and assigned is how the admin learns part of
        // the region was already someone else's.
        if ($result['matched'] > $result['assigned']) {
            $held = $result['matched'] - $result['assigned'];
            $message .= " {$held} dilewati karena sudah dipegang operator lain.";
        }

        return back()->with('success', $message);
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
