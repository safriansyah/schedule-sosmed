<?php
use App\Enums\{ContentStatus, Permission, RoleName};
use App\Models\{Content, Role, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

function userWithRole(RoleName $role): User {
    return User::withRole($role)->firstOrFail();
}

it('seeds the full permission matrix', function () {
    expect(Role::count())->toBe(5)
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
