<?php
use App\Enums\{ContentStatus, RoleName, SocialPlatform};
use App\Models\{Content, SocialAccount, User};
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

function draftFor(User $creative): Content {
    $account = SocialAccount::firstOrCreate(
        ['platform' => SocialPlatform::Instagram, 'external_id' => 'notif-acc'],
        ['name' => 'Notif IG', 'access_token' => 'x', 'is_active' => true],
    );

    $content = Content::create([
        'title' => 'Konten Notifikasi', 'status' => ContentStatus::Draft,
        'created_by' => $creative->id, 'scheduled_at' => now()->addDay(),
    ]);
    $content->schedules()->create([
        'social_account_id' => $account->id, 'scheduled_at' => now()->addDay(),
        'status' => ContentStatus::Draft,
    ]);

    return $content;
}

it('notifies curators when a draft is submitted', function () {
    $creative = User::withRole(RoleName::Creative)->firstOrFail();
    $curator = User::withRole(RoleName::Curator)->firstOrFail();

    $before = $curator->unreadNotifications()->count();

    app(WorkflowService::class)->submit(draftFor($creative), $creative);

    expect($curator->unreadNotifications()->count())->toBe($before + 1);

    $latest = $curator->unreadNotifications()->latest()->first();
    expect($latest->data['title'])->toBe('Konten baru menunggu approval')
        ->and($latest->data['event'])->toBe('content.submitted');
});

it('notifies verifiers on approval and the creator on revision', function () {
    $creative = User::withRole(RoleName::Creative)->firstOrFail();
    $curator = User::withRole(RoleName::Curator)->firstOrFail();
    $verifier = User::withRole(RoleName::Verifier)->firstOrFail();
    $workflow = app(WorkflowService::class);

    // Approval path -> verifier hears about it
    $a = draftFor($creative);
    $workflow->submit($a, $creative);
    $vBefore = $verifier->unreadNotifications()->count();
    $workflow->approve($a, $curator, 'Bagus');
    expect($verifier->unreadNotifications()->count())->toBe($vBefore + 1);

    // Revision path -> the creative hears about it, with the note
    $b = draftFor($creative);
    $workflow->submit($b, $creative);
    $cBefore = $creative->unreadNotifications()->count();
    $workflow->requestRevision($b, $curator, 'Tolong perbaiki caption');

    expect($creative->unreadNotifications()->count())->toBeGreaterThan($cBefore);

    // Timestamps can tie within the same second, so look for the note rather
    // than assuming which notification is newest.
    $bodies = $creative->unreadNotifications()->get()->pluck('data.body');
    expect($bodies->filter(fn ($b) => str_contains($b, 'Tolong perbaiki caption')))->not->toBeEmpty();
});

it('shows the inbox and marks notifications as read', function () {
    $creative = User::withRole(RoleName::Creative)->firstOrFail();
    $curator = User::withRole(RoleName::Curator)->firstOrFail();

    app(WorkflowService::class)->submit(draftFor($creative), $creative);

    $this->actingAs($curator)->get(route('notifications.index'))
        ->assertOk()->assertSee('Konten baru menunggu approval');

    $notification = $curator->unreadNotifications()->latest()->firstOrFail();

    $this->actingAs($curator)->post(route('notifications.read', $notification->id))
        ->assertRedirect();

    expect($curator->unreadNotifications()->where('id', $notification->id)->exists())->toBeFalse();
});

it('keeps each inbox private', function () {
    $creative = User::withRole(RoleName::Creative)->firstOrFail();
    $curator = User::withRole(RoleName::Curator)->firstOrFail();
    $verifier = User::withRole(RoleName::Verifier)->firstOrFail();

    app(WorkflowService::class)->submit(draftFor($creative), $creative);

    $notification = $curator->unreadNotifications()->latest()->firstOrFail();

    // A different user cannot mark someone else's notification as read.
    $this->actingAs($verifier)->post(route('notifications.read', $notification->id))
        ->assertNotFound();
});
