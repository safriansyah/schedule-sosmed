<?php

/**
 * "Tugas Petugas": the supervisor's view of everyone's workload.
 *
 * Deliberately a SEPARATE tab from "Tugas Saya" rather than making that tab
 * mean something different per role. A label that shows one person their own
 * work and another person the whole team's is a label that lies, and it leaves
 * a supervisor who genuinely holds a task with no way to find it.
 */

use App\Enums\Permission;
use App\Enums\RoleName;
use App\Models\Interaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

/** One open interaction assigned to the given user. */
function assignedComment(\App\Models\User $to, string $text = 'Contoh tugas'): Interaction
{
    $interaction = instagramComment();

    $interaction->forceFill([
        'external_id' => 'SUP-'.uniqid(),
        'author_handle' => 'sup_'.uniqid(),
        'text' => $text,
        'status' => \App\Enums\InteractionStatus::InProgress->value,
        'assigned_to' => $to->id,
    ])->save();

    return $interaction->refresh();
}

it('gives the oversight tab to supervisors and withholds it from field roles', function () {
    $supervisors = [RoleName::SuperAdmin, RoleName::Director, RoleName::Manager];
    $field = [RoleName::Operator, RoleName::Pic];

    foreach ($supervisors as $role) {
        expect(\App\Models\User::withRole($role)->first()->hasPermission(Permission::ViewAllInteractions))
            ->toBeTrue("{$role->value} harus bisa melihat tugas semua petugas");
    }

    foreach ($field as $role) {
        expect(\App\Models\User::withRole($role)->first()->hasPermission(Permission::ViewAllInteractions))
            ->toBeFalse("{$role->value} tidak boleh melihat antrean rekan");
    }
});

it('shows a supervisor every assigned task, with the owner named', function () {
    $operator = operatorNamed('sup-op@test.local');
    $pic = operatorNamed('sup-pic@test.local', RoleName::Pic);

    assignedComment($operator, 'Tugas milik operator');
    assignedComment($pic, 'Tugas milik PIC');

    $response = $this->actingAs(admin())->get(route('interactions.index', ['tab' => 'assigned']));

    $response->assertOk()
        ->assertSee('Tugas milik operator')
        ->assertSee('Tugas milik PIC')
        // The point of the tab: knowing WHOSE each one is.
        ->assertSee($operator->name)
        ->assertSee($pic->name);
});

it('lets the director look but not touch', function () {
    $operator = operatorNamed('sup-op2@test.local');
    $comment = assignedComment($operator, 'Tugas yang dipantau direktur');

    $director = \App\Models\User::withRole(RoleName::Director)->first();

    $this->actingAs($director)
        ->get(route('interactions.index', ['tab' => 'assigned']))
        ->assertOk()
        ->assertSee('Tugas yang dipantau direktur')
        ->assertSee($operator->name);

    // Oversight is read-only: the director may not reassign or handle.
    expect($director->hasPermission(Permission::HandleInteractions))->toBeFalse()
        ->and($director->hasPermission(Permission::AssignInteractions))->toBeFalse();

    // The comment page carries no handling controls at all any more, so the
    // read-only guarantee is checked where handling still happens: the inbox's
    // bulk actions.
    $this->actingAs($director)
        ->post(route('interactions.bulk'), [
            'ids' => [$comment->id],
            'action' => 'assign',
            'assigned_to' => $operator->id,
        ])
        ->assertForbidden();
});

it('keeps Tugas Saya meaning strictly mine, for everyone', function () {
    $operator = operatorNamed('sup-op3@test.local');
    $other = operatorNamed('sup-op4@test.local');

    assignedComment($operator, 'Punya operator tiga');
    assignedComment($other, 'Punya operator empat');

    // The operator sees only their own…
    $this->actingAs($operator)->get(route('interactions.index', ['tab' => 'mine']))
        ->assertOk()
        ->assertSee('Punya operator tiga')
        ->assertDontSee('Punya operator empat');

    // …and so does the admin, whose own queue is empty.
    $this->actingAs(admin())->get(route('interactions.index', ['tab' => 'mine']))
        ->assertOk()
        ->assertDontSee('Punya operator tiga')
        ->assertDontSee('Punya operator empat');
});

it('points a supervisor with an empty queue at the tab that answers them', function () {
    assignedComment(operatorNamed('sup-op5@test.local'));

    $this->actingAs(admin())->get(route('interactions.index', ['tab' => 'mine']))
        ->assertOk()
        ->assertSee('Tidak ada tugas atas nama Anda')
        ->assertSee('Lihat Tugas Petugas')
        ->assertSee(route('interactions.index', ['tab' => 'assigned']), false);
});

it('refuses the oversight tab to a field role that types the URL', function () {
    $operator = operatorNamed('sup-op6@test.local');
    $other = operatorNamed('sup-op7@test.local');

    assignedComment($other, 'Antrean rekan yang tidak boleh terbaca');

    // Not an error page — the tab simply does not exist for them, so the
    // request falls back to the default queue.
    $this->actingAs($operator)
        ->get(route('interactions.index', ['tab' => 'assigned']))
        ->assertOk()
        ->assertDontSee('Antrean rekan yang tidak boleh terbaca');
});

it('ignores the handler filter for someone who may not use it', function () {
    $operator = operatorNamed('sup-op8@test.local');
    $other = operatorNamed('sup-op9@test.local');

    assignedComment($other, 'Tugas rekan lewat filter');

    // Hand-typing ?handler=<colleague id> must not open their queue.
    $this->actingAs($operator)
        ->get(route('interactions.index', ['tab' => 'all', 'handler' => $other->id]))
        ->assertOk();

    // But a supervisor gets exactly what they asked for.
    $this->actingAs(admin())
        ->get(route('interactions.index', ['tab' => 'assigned', 'handler' => $other->id]))
        ->assertOk()
        ->assertSee('Tugas rekan lewat filter');
});
