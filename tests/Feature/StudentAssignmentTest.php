<?php

/**
 * Acceptance tests A–D: import, filter, assign by tick-box, assign by region.
 *
 * The arithmetic in these is the point. "Operator A = 2, Belum Assigned = 8"
 * is the guarantee the whole hand-out feature rests on, and it has to hold
 * when two people press the button at once.
 */

use App\Enums\AssignmentStatus;
use App\Enums\RoleName;
use App\Enums\StudentCondition;
use App\Models\Student;
use App\Models\User;
use App\Services\Students\StudentAssigner;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

/** Ten students matching the brief's dummy data, all unassigned. */
function seedStudents(int $count = 10): void
{
    $rows = [
        ['Bangka', 'Sungailiat', StudentCondition::AdmisiTidakBayar],
        ['Bangka', 'Belinyu', StudentCondition::OngoingBillingPending],
        ['Bangka Tengah', 'Koba', StudentCondition::MabaBelumRegMk],
        ['Pangkalpinang', 'Bukit Intan', StudentCondition::OngoingTidakRegistrasi],
        ['Bangka', 'Sungailiat', StudentCondition::AdmisiTidakBayar],
        ['Bangka Selatan', 'Toboali', StudentCondition::OngoingBillingPending],
        ['Bangka', 'Mendo Barat', StudentCondition::MabaBelumRegMk],
        ['Bangka Tengah', 'Pangkalan Baru', StudentCondition::OngoingTidakRegistrasi],
        ['Pangkalpinang', 'Girimaya', StudentCondition::AdmisiTidakBayar],
        ['Bangka Barat', 'Mentok', StudentCondition::OngoingBillingPending],
    ];

    for ($i = 0; $i < $count; $i++) {
        [$kabupaten, $kecamatan, $condition] = $rows[$i % count($rows)];

        Student::create([
            'nim' => 'TEST'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            'nac' => 'NACTEST'.$i,
            'nama' => "Mahasiswa Uji {$i}",
            'kabupaten' => $kabupaten,
            'kecamatan' => $kecamatan,
            'semester_terakhir' => '2026.1',
            'kategori_masalah' => $condition->value,
            'assignment_status' => AssignmentStatus::Unassigned->value,
        ]);
    }
}

function testStudents()
{
    return Student::where('nim', 'like', 'TEST%');
}

/* -----------------------------------------------------------------
 | Test A — dummy import
 * ----------------------------------------------------------------- */

it('lands every imported student on belum_assigned with no operator', function () {
    seedStudents(10);

    expect(testStudents()->count())->toBe(10)
        ->and(testStudents()->whereNotNull('assigned_to')->count())->toBe(0)
        ->and(testStudents()->unassigned()->count())->toBe(10);
});

/* -----------------------------------------------------------------
 | Test B — region filter
 * ----------------------------------------------------------------- */

it('filters students by kabupaten and kecamatan', function () {
    seedStudents(10);

    $bangka = testStudents()->filtered(['kabupaten' => 'Bangka'])->get();

    expect($bangka)->not->toBeEmpty()
        ->and($bangka->pluck('kabupaten')->unique()->all())->toBe(['Bangka']);

    $sungailiat = testStudents()
        ->filtered(['kabupaten' => 'Bangka', 'kecamatan' => 'Sungailiat'])
        ->get();

    expect($sungailiat)->not->toBeEmpty()
        ->and($sungailiat->pluck('kecamatan')->unique()->all())->toBe(['Sungailiat']);
});

it('filters students by condition', function () {
    seedStudents(10);

    $unpaid = testStudents()
        ->filtered(['kondisi' => StudentCondition::AdmisiTidakBayar->value])
        ->get();

    expect($unpaid)->not->toBeEmpty()
        ->and($unpaid->pluck('kategori_masalah')->unique()->all())
        ->toBe([StudentCondition::AdmisiTidakBayar]);
});

/* -----------------------------------------------------------------
 | Test C — assign selected
 * ----------------------------------------------------------------- */

it('assigns the selected students and leaves the rest unassigned', function () {
    seedStudents(10);

    $admin = User::withRole(RoleName::SuperAdmin)->first();
    $operatorA = operatorNamed('op-a@test.local');

    $picked = testStudents()->orderBy('id')->limit(2)->pluck('id')->all();

    $result = app(StudentAssigner::class)->assignSelected($picked, $operatorA, $admin);

    expect($result['assigned'])->toBe(2)
        ->and(testStudents()->assignedTo($operatorA->id)->count())->toBe(2)
        ->and(testStudents()->unassigned()->count())->toBe(8);

    // Status moves with the assignment, not just the foreign key.
    expect(Student::find($picked[0])->assignment_status)->toBe(AssignmentStatus::Assigned);
});

/* -----------------------------------------------------------------
 | Test D — assign by region
 |
 | The headcount form is gone. It cut the list at an arbitrary row, so two
 | students in the same village landed with different operators and neither
 | could plan a visit or recognise a name. The region is the batch now, and
 | these tests pin the arithmetic that replaced it.
 |
 | seedStudents(10) lays down: Bangka 4, Bangka Tengah 2, Pangkalpinang 2,
 | Bangka Selatan 1, Bangka Barat 1.
 * ----------------------------------------------------------------- */

it('assigns a whole region without touching students another operator holds', function () {
    seedStudents(10);

    $admin = User::withRole(RoleName::SuperAdmin)->first();
    $operatorA = operatorNamed('op-a@test.local');
    $operatorB = operatorNamed('op-b@test.local');
    $assigner = app(StudentAssigner::class);

    // Two of the four Bangka students are already A's.
    $picked = testStudents()->where('kabupaten', 'Bangka')->orderBy('id')->limit(2)->pluck('id')->all();
    $assigner->assignSelected($picked, $operatorA, $admin);

    $result = $assigner->assignByRegion(['kabupaten' => 'Bangka'], $operatorB, $admin);

    expect($result['assigned'])->toBe(2)
        ->and(testStudents()->assignedTo($operatorA->id)->count())->toBe(2)
        ->and(testStudents()->assignedTo($operatorB->id)->count())->toBe(2)
        ->and(testStudents()->unassigned()->count())->toBe(6);

    // matched counts the whole region, so the screen can say how many were
    // skipped rather than quietly handing over fewer than expected.
    expect($result['matched'])->toBeGreaterThanOrEqual(4);
});

it('never hands the same student to two operators', function () {
    seedStudents(10);

    $admin = User::withRole(RoleName::SuperAdmin)->first();
    $operatorA = operatorNamed('op-a@test.local');
    $operatorB = operatorNamed('op-b@test.local');
    $assigner = app(StudentAssigner::class);

    // Both admins hand out the same region.
    $first = $assigner->assignByRegion(['kabupaten' => 'Bangka'], $operatorA, $admin);
    $second = $assigner->assignByRegion(['kabupaten' => 'Bangka'], $operatorB, $admin);

    // The second run finds nothing left, rather than stealing them.
    expect($first['assigned'])->toBe(4)
        ->and($second['assigned'])->toBe(0)
        ->and(testStudents()->assignedTo($operatorA->id)->count())->toBe(4)
        ->and(testStudents()->assignedTo($operatorB->id)->count())->toBe(0);
});

it('refuses to hand out everybody when no region is chosen', function () {
    seedStudents(10);

    $admin = User::withRole(RoleName::SuperAdmin)->first();
    $operator = operatorNamed('op-a@test.local');

    // An empty form must not mean "all of them". There is no undo for a
    // hand-out of 7.400 rows.
    $result = app(StudentAssigner::class)->assignByRegion(
        ['provinsi' => null, 'kabupaten' => '', 'kecamatan' => null, 'kelurahan' => null, 'pokjar' => null],
        $operator,
        $admin,
    );

    expect($result['assigned'])->toBe(0)
        ->and($result['matched'])->toBe(0)
        ->and(testStudents()->unassigned()->count())->toBe(10);
});

it('does not reassign a student who already belongs to someone', function () {
    seedStudents(10);

    $admin = User::withRole(RoleName::SuperAdmin)->first();
    $operatorA = operatorNamed('op-a@test.local');
    $operatorB = operatorNamed('op-b@test.local');
    $assigner = app(StudentAssigner::class);

    $picked = testStudents()->orderBy('id')->limit(3)->pluck('id')->all();
    $assigner->assignSelected($picked, $operatorA, $admin);

    // Ticking the same rows for a different operator is refused by default…
    $result = $assigner->assignSelected($picked, $operatorB, $admin);

    expect($result['assigned'])->toBe(0)
        ->and($result['skipped'])->toBe(3)
        ->and(testStudents()->assignedTo($operatorA->id)->count())->toBe(3);

    // …unless the caller explicitly asks to move them.
    $forced = $assigner->assignSelected($picked, $operatorB, $admin, reassign: true);

    expect($forced['assigned'])->toBe(3)
        ->and(testStudents()->assignedTo($operatorB->id)->count())->toBe(3)
        ->and(testStudents()->assignedTo($operatorA->id)->count())->toBe(0);
});

it('gives different regions to different operators without overlap', function () {
    seedStudents(10);

    $admin = User::withRole(RoleName::SuperAdmin)->first();
    $operatorA = operatorNamed('op-a@test.local');
    $operatorB = operatorNamed('op-b@test.local');
    $assigner = app(StudentAssigner::class);

    $assigner->assignByRegion(['kabupaten' => 'Bangka'], $operatorA, $admin);
    $assigner->assignByRegion(['kabupaten' => 'Bangka Tengah'], $operatorB, $admin);

    expect(testStudents()->assignedTo($operatorA->id)->count())->toBe(4)
        ->and(testStudents()->assignedTo($operatorB->id)->count())->toBe(2)
        ->and(testStudents()->unassigned()->count())->toBe(4);

    // No overlap: "Bangka" must not swallow "Bangka Tengah" through a LIKE.
    $aIds = testStudents()->assignedTo($operatorA->id)->pluck('id')->all();
    $bIds = testStudents()->assignedTo($operatorB->id)->pluck('id')->all();

    expect(array_intersect($aIds, $bIds))->toBe([]);
});

it('narrows to the deepest region level chosen', function () {
    seedStudents(10);

    $admin = User::withRole(RoleName::SuperAdmin)->first();
    $operator = operatorNamed('op-a@test.local');
    $other = operatorNamed('op-b@test.local');
    $assigner = app(StudentAssigner::class);

    // Kabupaten only: both Pangkalpinang students.
    $result = $assigner->assignByRegion(['kabupaten' => 'Pangkalpinang'], $operator, $admin);

    $held = testStudents()->assignedTo($operator->id)->get();

    expect($result['assigned'])->toBe($held->count())
        ->and($held->pluck('kabupaten')->unique()->all())->toBe(['Pangkalpinang']);

    // Kabupaten + kecamatan: only the two Sungailiat rows out of four Bangka.
    $deeper = $assigner->assignByRegion(
        ['kabupaten' => 'Bangka', 'kecamatan' => 'Sungailiat'],
        $other,
        $admin,
    );

    expect($deeper['assigned'])->toBe(2)
        ->and(testStudents()->assignedTo($other->id)->pluck('kecamatan')->unique()->all())
        ->toBe(['Sungailiat']);
});

it('returns students to the pool when released', function () {
    seedStudents(10);

    $admin = User::withRole(RoleName::SuperAdmin)->first();
    $operator = operatorNamed('op-a@test.local');
    $assigner = app(StudentAssigner::class);

    $picked = testStudents()->orderBy('id')->limit(4)->pluck('id')->all();
    $assigner->assignSelected($picked, $operator, $admin);

    expect($assigner->unassign($picked, $admin))->toBe(4)
        ->and(testStudents()->unassigned()->count())->toBe(10);
});

/* -----------------------------------------------------------------
 | Permission scoping
 * ----------------------------------------------------------------- */

it('shows an operator only the students assigned to them', function () {
    seedStudents(10);

    $admin = User::withRole(RoleName::SuperAdmin)->first();
    $operatorA = operatorNamed('op-a@test.local');
    $operatorB = operatorNamed('op-b@test.local');
    $assigner = app(StudentAssigner::class);

    $picked = testStudents()->orderBy('id')->limit(2)->pluck('id')->all();
    $assigner->assignSelected($picked, $operatorA, $admin);
    $assigner->assignByRegion(['kabupaten' => 'Bangka Tengah'], $operatorB, $admin);

    expect(Student::visibleTo($operatorA)->where('nim', 'like', 'TEST%')->count())->toBe(2)
        ->and(Student::visibleTo($operatorB)->where('nim', 'like', 'TEST%')->count())->toBe(2)
        ->and(Student::visibleTo($admin)->where('nim', 'like', 'TEST%')->count())->toBe(10);
});

it('forbids an operator from opening another operator’s student', function () {
    seedStudents(10);

    $admin = User::withRole(RoleName::SuperAdmin)->first();
    $operatorA = operatorNamed('op-a@test.local');
    $operatorB = operatorNamed('op-b@test.local');

    $picked = testStudents()->orderBy('id')->limit(1)->pluck('id')->all();
    app(StudentAssigner::class)->assignSelected($picked, $operatorA, $admin);

    $student = Student::find($picked[0]);

    $this->actingAs($operatorA)->get(route('students.show', $student))->assertOk();
    $this->actingAs($operatorB)->get(route('students.show', $student))->assertForbidden();
});
