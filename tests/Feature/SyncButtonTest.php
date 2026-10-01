<?php

/**
 * The "Sinkron sekarang" button.
 *
 * It used to run the whole sync inside the POST, so the request stayed open for
 * as long as Instagram took — one API call per post refreshed. The page looked
 * frozen, and the natural response to a frozen page is to click again, which
 * started a second sweep on top of the first.
 *
 * So what matters here is: the request returns immediately, a second click does
 * not start a second run, and a queue worker that never picks the job up is
 * reported rather than left spinning.
 */

use App\Enums\RoleName;
use App\Jobs\RunQuickSync;
use App\Models\User;
use App\Services\Publishing\SyncStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

beforeEach(function () {
    app(SyncStatus::class)->clear();
});

afterEach(function () {
    app(SyncStatus::class)->clear();
});

function syncAdmin(): User
{
    return User::withRole(RoleName::SuperAdmin)->firstOrFail();
}

it('queues the work instead of doing it in the request', function () {
    Queue::fake();

    $this->actingAs(syncAdmin())->post(route('sync.now'))->assertRedirect();

    Queue::assertPushed(RunQuickSync::class, 1);
});

it('refuses to start a second run while one is in flight', function () {
    Queue::fake();
    $admin = syncAdmin();

    $this->actingAs($admin)->post(route('sync.now'));
    $this->actingAs($admin)->post(route('sync.now'));

    // Two clicks, one sweep. Otherwise the second doubles the API calls of the
    // first, for no new data.
    Queue::assertPushed(RunQuickSync::class, 1);
});

it('answers the button with json rather than a redirect it must follow', function () {
    Queue::fake();

    $this->actingAs(syncAdmin())
        ->postJson(route('sync.now'))
        ->assertOk()
        ->assertJsonPath('status', 'queued');
});

it('reports progress through the status endpoint', function () {
    $admin = syncAdmin();
    $status = app(SyncStatus::class);

    $this->actingAs($admin)->get(route('sync.status'))->assertOk()->assertJsonPath('status', 'idle');

    $status->running();
    $this->actingAs($admin)->get(route('sync.status'))->assertOk()->assertJsonPath('status', 'running');

    $status->done('Sinkron selesai — 1 akun.');
    $this->actingAs($admin)->get(route('sync.status'))
        ->assertOk()
        ->assertJsonPath('status', 'done')
        ->assertJsonPath('message', 'Sinkron selesai — 1 akun.');
});

it('says so when nothing picks the job up', function () {
    $status = app(SyncStatus::class);
    $status->queued();

    // The job has sat unclaimed for well past the time a worker would take.
    // This is the normal failure on a desktop: the `composer run dev:lan`
    // window was closed, so there is no worker at all and NOTHING would
    // otherwise report anything.
    $this->travel(2)->minutes();

    $state = $status->current();

    expect($state['stalled'])->toBeTrue();
    expect($state['hint'])->toContain('queue:work');

    // And the button must be usable again rather than wedged.
    expect($status->isBusy())->toBeFalse();
});

it('frees the button when a run dies halfway', function () {
    $status = app(SyncStatus::class);
    $status->running();

    $this->travel(20)->minutes();

    expect($status->current()['stalled'])->toBeTrue();
    expect($status->isBusy())->toBeFalse();
});

it('marks the run failed when the job blows up', function () {
    $this->mock(App\Services\Publishing\QuickSync::class, function ($mock) {
        $mock->shouldReceive('run')->andThrow(new RuntimeException('API mati'));
    });

    app(RunQuickSync::class)->handle(app(App\Services\Publishing\QuickSync::class), app(SyncStatus::class));

    $state = app(SyncStatus::class)->current();

    expect($state['status'])->toBe('failed');
    expect($state['message'])->toContain('API mati');
});

it('keeps the button on the pages that carry it', function () {
    $admin = syncAdmin();

    foreach (['/dashboard', '/monitoring'] as $uri) {
        $this->actingAs($admin)->get($uri)->assertOk()->assertSee('Sinkron Sekarang');
    }
});
