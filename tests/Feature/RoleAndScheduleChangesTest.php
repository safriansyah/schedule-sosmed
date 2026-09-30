<?php
use App\Enums\{ContentStatus, Permission, RoleName};
use App\Models\{Content, User};
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('grants calendar-note permission to every role that sees the calendar', function () {
    // Roles that hold the permission, not every role there is. "Operator Follow
// Up" deliberately does without the read baseline the other roles share — its
// whole job is following up its own tickets — so iterating RoleName::cases()
// here would assert that a restricted role is not restricted.
    foreach (RoleName::cases() as $role) {
        $user = User::withRole($role)->firstOrFail();

        if (! $user->hasPermission(Permission::ViewCalendar)) {
            continue;
        }

        expect($user->hasPermission(Permission::ManageCalendarNotes))
            ->toBeTrue("role {$role->value} should be able to add calendar notes");
    }
});

it('keeps the follow-up role out of the calendar entirely', function () {
    // Both halves: it cannot read the calendar, so it must not be able to
    // write notes onto it either.
    $user = User::withRole(RoleName::FollowUp)->firstOrFail();

    expect($user->hasPermission(Permission::ViewCalendar))->toBeFalse();
    expect($user->hasPermission(Permission::ManageCalendarNotes))->toBeFalse();
});

it('limits UT Monitoring Account to admin and director only', function () {
    foreach ([RoleName::SuperAdmin, RoleName::Director] as $role) {
        expect(User::withRole($role)->firstOrFail()->hasPermission(Permission::ViewDatasets))->toBeTrue();
    }
    foreach ([RoleName::Creative, RoleName::Curator, RoleName::Verifier] as $role) {
        expect(User::withRole($role)->firstOrFail()->hasPermission(Permission::ViewDatasets))->toBeFalse();
    }
});

it('lets the curator set the publish schedule when approving', function () {
    $creative = User::withRole(RoleName::Creative)->firstOrFail();
    $curator  = User::withRole(RoleName::Curator)->firstOrFail();

    $content = Content::create([
        'title' => 'Jadwal oleh Curator', 'status' => ContentStatus::WaitingApproval, 'created_by' => $creative->id,
    ]);

    $when = now()->addDays(3)->startOfMinute();
    app(WorkflowService::class)->approve($content, $curator, null, ['schedule_at' => $when->toDateTimeString()]);

    $fresh = $content->fresh();
    expect($fresh->status)->toBe(ContentStatus::WaitingVerification);
    expect($fresh->scheduled_at?->format('Y-m-d H:i'))->toBe($when->format('Y-m-d H:i'));
});

it('no longer shows a topbar notification bell', function () {
    // Notifications moved to actionable badges on the workflow menus instead.
    $this->actingAs(User::withRole(RoleName::Creative)->firstOrFail())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('aria-label="Notifikasi"', false);
});

it('no longer shows the schedule field on the content create form', function () {
    $this->actingAs(User::withRole(RoleName::Creative)->firstOrFail())
        ->get(route('contents.create'))
        ->assertOk()
        ->assertDontSee('name="scheduled_at"', false)
        ->assertSee('ditentukan oleh tim');
});
