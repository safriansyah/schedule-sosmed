<?php
use App\Enums\{ContentStatus, Permission, RoleName};
use App\Models\{Content, Role, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

function userWithRole(RoleName $role): User {
    return User::withRole($role)->firstOrFail();
}

it('seeds the full permission matrix', function () {
    // Counted from the enums rather than hard-coded, so adding a role or a
    // permission does not fail a test that is really about the seeder running.
    expect(Role::count())->toBe(count(RoleName::cases()))
        ->and(\App\Models\Permission::count())->toBe(count(Permission::cases()));
});

it('gives super admin everything via the gate bypass', function () {
    $sa = userWithRole(RoleName::SuperAdmin);
    foreach (Permission::cases() as $p) {
        expect($sa->hasPermission($p))->toBeTrue();
    }
});

it('keeps the director read-only', function () {
    $d = userWithRole(RoleName::Director);
    expect($d->isReadOnly())->toBeTrue()
        ->and($d->hasPermission(Permission::ViewAnalytics))->toBeTrue()
        ->and($d->hasPermission(Permission::ViewMonitoring))->toBeTrue()
        ->and($d->hasPermission(Permission::CreateContent))->toBeFalse()
        ->and($d->can('create', Content::class))->toBeFalse();
});

it('lets creative create but not approve or verify', function () {
    $c = userWithRole(RoleName::Creative);
    expect($c->can('create', Content::class))->toBeTrue()
        ->and($c->hasPermission(Permission::DecideApproval))->toBeFalse()
        ->and($c->hasPermission(Permission::DecideVerification))->toBeFalse();
});

it('scopes creative edits to their own open drafts', function () {
    $a = userWithRole(RoleName::Creative);
    $b = User::withRole(RoleName::Creative)->where('id', '!=', $a->id)->firstOrFail();

    $draft = Content::create([
        'title' => 'Draft A', 'status' => ContentStatus::Draft, 'created_by' => $a->id,
    ]);

    expect($a->can('update', $draft))->toBeTrue()
        ->and($b->can('update', $draft))->toBeFalse();

    // Once it leaves the creative's hands it is locked.
    $draft->update(['status' => ContentStatus::WaitingApproval]);
    expect($a->can('update', $draft->fresh()))->toBeFalse();
});

it('gates approval and verification on the right status', function () {
    $creative = userWithRole(RoleName::Creative);
    $curator  = userWithRole(RoleName::Curator);
    $verifier = userWithRole(RoleName::Verifier);

    $content = Content::create([
        'title' => 'Alur', 'status' => ContentStatus::WaitingApproval, 'created_by' => $creative->id,
    ]);

    expect($curator->can('decideApproval', $content))->toBeTrue()
        ->and($verifier->can('decideApproval', $content))->toBeFalse()
        ->and($verifier->can('decideVerification', $content))->toBeFalse();

    $content->update(['status' => ContentStatus::WaitingVerification]);
    $content = $content->fresh();

    expect($verifier->can('decideVerification', $content))->toBeTrue()
        ->and($curator->can('decideApproval', $content))->toBeFalse();
});

/* -----------------------------------------------------------------
 | Handling roles can finish what they start
 * ----------------------------------------------------------------- */

it('lets every handling role close a ticket they worked', function () {
    // PIC used to be able to do every step except the last, which also left
    // Operator — the field role — with more authority on the same flow.
    foreach ([RoleName::Manager, RoleName::Pic, RoleName::Operator] as $role) {
        $user = \App\Models\User::withRole($role)->firstOrFail();

        expect($user->hasPermission(\App\Enums\Permission::HandleTickets))->toBeTrue()
            ->and($user->hasPermission(\App\Enums\Permission::CloseTickets))->toBeTrue();
    }
});

it('keeps reassignment a manager decision', function () {
    // The restriction that IS deliberate: a handler finishes their own ticket
    // but does not move work between people.
    foreach ([RoleName::Pic, RoleName::Operator] as $role) {
        expect(\App\Models\User::withRole($role)->firstOrFail()
            ->hasPermission(\App\Enums\Permission::AssignTickets))->toBeFalse();
    }

    expect(\App\Models\User::withRole(RoleName::Manager)->firstOrFail()
        ->hasPermission(\App\Enums\Permission::AssignTickets))->toBeTrue();
});

it('keeps the director read-only across the handling modules', function () {
    $director = \App\Models\User::withRole(RoleName::Director)->firstOrFail();

    foreach ([
        \App\Enums\Permission::HandleTickets,
        \App\Enums\Permission::CloseTickets,
        \App\Enums\Permission::AssignTickets,
        \App\Enums\Permission::AssignStudents,
        \App\Enums\Permission::ManageTasks,
        \App\Enums\Permission::ManageSettings,
    ] as $permission) {
        expect($director->hasPermission($permission))->toBeFalse();
    }

    // …but they can read the reports and every ticket, which is the job.
    expect($director->hasPermission(\App\Enums\Permission::ViewReports))->toBeTrue()
        ->and($director->hasPermission(\App\Enums\Permission::ViewAllTickets))->toBeTrue();
});

it('keeps site identity behind the settings permission', function () {
    foreach ([RoleName::Manager, RoleName::Pic, RoleName::Operator, RoleName::Director] as $role) {
        $this->actingAs(\App\Models\User::withRole($role)->firstOrFail())
            ->get(route('settings.site.edit'))
            ->assertForbidden();
    }

    $this->actingAs(\App\Models\User::withRole(RoleName::SuperAdmin)->firstOrFail())
        ->get(route('settings.site.edit'))
        ->assertOk();
});
