<?php

/**
 * Retention on the append-only tables.
 *
 * The risk is not that it fails to delete — it is that it deletes too much.
 * MetricsComparison draws every growth figure from media_metrics, so a prune
 * that reached into the recent window would turn the dashboard flat without
 * erroring, and the rows would be gone. Hence the floor on the options and the
 * assertions below about what SURVIVES.
 */

use App\Models\{AccountMedia, MediaMetric};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

/** A snapshot of an existing post, dated. */
function snapshotAged(int $daysAgo): MediaMetric
{
    $media = AccountMedia::firstOrFail();

    return MediaMetric::create([
        'account_media_id' => $media->id,
        'captured_at' => now()->subDays($daysAgo),
        'captured_on' => now()->subDays($daysAgo)->toDateString(),
        'likes' => 1, 'comments' => 0, 'views' => 0, 'reach' => 0,
    ]);
}

it('removes snapshots past the window and keeps the rest', function () {
    $old = snapshotAged(500);
    $recent = snapshotAged(10);

    $this->artisan('data:prune', ['--force' => true])->assertSuccessful();

    expect(MediaMetric::find($old->id))->toBeNull();
    expect(MediaMetric::find($recent->id))->not->toBeNull();
});

it('changes nothing on a dry run', function () {
    $old = snapshotAged(500);

    $this->artisan('data:prune', ['--dry-run' => true])->assertSuccessful();

    expect(MediaMetric::find($old->id))->not->toBeNull();
});

it('refuses a window short enough to destroy the charts', function () {
    // --metrics=1 would delete almost everything the comparison reads. The
    // floor turns a typo into a harmless no-op rather than a silent wipe.
    $recent = snapshotAged(20);

    $this->artisan('data:prune', ['--metrics' => 1, '--force' => true])->assertSuccessful();

    expect(MediaMetric::find($recent->id))->not->toBeNull();
});

it('leaves the posts and comments themselves alone', function () {
    $posts = AccountMedia::count();
    $interactions = DB::table('interactions')->count();

    snapshotAged(500);

    $this->artisan('data:prune', ['--force' => true])->assertSuccessful();

    // Original content is never retention material — only derived snapshots
    // and the audit log are.
    expect(AccountMedia::count())->toBe($posts);
    expect(DB::table('interactions')->count())->toBe($interactions);
});
