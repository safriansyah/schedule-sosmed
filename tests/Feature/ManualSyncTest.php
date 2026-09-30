<?php

/**
 * The manual sync, end to end.
 *
 * The button no longer does the work in the request — it enqueues RunQuickSync
 * and the page polls for the outcome. So the result message that used to arrive
 * in the toast now arrives through SyncStatus once the worker has run, and that
 * is what these assert.
 *
 * SyncButtonTest covers the queueing and the failure modes; this covers the
 * happy path actually producing a result.
 */

use App\Enums\RoleName;
use App\Jobs\RunQuickSync;
use App\Models\User;
use App\Services\Publishing\SyncStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

beforeEach(fn () => app(SyncStatus::class)->clear());
afterEach(fn () => app(SyncStatus::class)->clear());

it('shows a working sync button on the dashboard', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Sinkron Sekarang');
});

it('runs a manual sync and reports the result', function () {
    // Fake the whole Instagram surface so the button is deterministic.
    Http::fake([
        '*/me?*' => Http::response(['followers_count' => 4000, 'follows_count' => 2, 'media_count' => 3]),
        '*/me/media*' => Http::response(['data' => [
            ['id' => '111', 'caption' => 'Halo', 'media_type' => 'IMAGE',
             'media_product_type' => 'FEED', 'timestamp' => now()->toIso8601String()],
        ]]),
        '*/111/insights*' => Http::response(['data' => [
            ['name' => 'likes', 'values' => [['value' => 50]]],
            ['name' => 'views', 'values' => [['value' => 200]]],
        ]]),
        '*/111/comments*' => Http::response(['data' => []]),
        '*' => Http::response(['data' => []]),
    ]);

    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    $this->actingAs($admin)->post(route('sync.now'))
        ->assertRedirect()
        ->assertSessionHas('toast');

    // The click only promises that it STARTED — a minute of Instagram calls is
    // not something to hold a browser open for. The outcome arrives separately,
    // through the status the page polls.
    expect(session('toast')['message'])->toContain('latar belakang');

    // The test queue runs jobs inline (QUEUE_CONNECTION=sync in phpunit.xml),
    // so by now the worker's part has happened too — which makes this a real
    // dispatch-to-result check rather than two halves tested apart.
    $state = app(SyncStatus::class)->current();

    expect($state['status'])->toBe('done');
    expect($state['message'])->toContain('Sinkron selesai');
});

it('gives every role access to trigger a sync', function () {
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => []])]);

    foreach (RoleName::cases() as $role) {
        // Cleared between roles, otherwise the busy guard would short-circuit
        // every role after the first and this would assert nothing.
        app(SyncStatus::class)->clear();

        $this->actingAs(User::withRole($role)->firstOrFail())
            ->post(route('sync.now'))->assertRedirect();
    }

    Queue::assertPushed(RunQuickSync::class, count(RoleName::cases()));
});
