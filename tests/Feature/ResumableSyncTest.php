<?php

/**
 * The syncs have to RESUME, not restart.
 *
 * Both used to begin at the newest post every time. `accounts:sync-comments`
 * took the newest 40 within the age window, so on an account with 2.200 posts
 * it covered the same 40 forever — running it a hundred times never reached
 * post 41, and nothing said so. The insights walk had the same shape one level
 * up: the per-run page ceiling meant the account was permanently capped at
 * 2.000 posts.
 *
 * So the expectation worth pinning is not "it fetches comments" but "the
 * second run does DIFFERENT work from the first".
 */

use App\Enums\SocialPlatform;
use App\Models\{AccountMedia, SocialAccount};
use App\Services\Publishing\{InstagramCommentSync, InstagramInsightsSync};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

function resumeAccount(): SocialAccount
{
    // Every other account off, so the sweep only sees this one.
    SocialAccount::query()->update(['is_active' => false]);

    return SocialAccount::create([
        'name' => 'Resume '.uniqid(),
        'username' => 'resume_'.uniqid(),
        'platform' => SocialPlatform::Instagram,
        'external_id' => 'res-'.uniqid(),
        'access_token' => 'token-resume',
        'is_active' => true,
    ]);
}

/** A post old enough that the refresh pass would skip it. */
function resumePost(SocialAccount $account, int $daysAgo, string $code): AccountMedia
{
    return AccountMedia::create([
        'social_account_id' => $account->id,
        'external_id' => 'ext-'.$code,
        'caption' => 'Post '.$code,
        'media_type' => 'IMAGE',
        'product_type' => 'FEED',
        'permalink' => 'https://www.instagram.com/p/'.$code.'/',
        'posted_at' => now()->subDays($daysAgo),
    ]);
}

/** The viewer, answering with no comments — this is about WHICH posts are hit. */
function fakeViewer(): void
{
    Http::fake([
        '*/api/ins/post-comment/comments/*' => Http::response([
            'code' => 0,
            'data' => ['comment_count' => 0, 'comments' => []],
        ]),
    ]);
}

it('walks to the next batch of posts on each backfill run', function () {
    $account = resumeAccount();
    fakeViewer();

    // Well outside the 120-day comment window, so only the backfill can see them.
    foreach (range(1, 5) as $i) {
        resumePost($account, 300 + $i, 'kode'.$i);
    }

    $sync = app(InstagramCommentSync::class);

    $first = $sync->syncAll(2, backfill: true);
    $done = AccountMedia::where('social_account_id', $account->id)
        ->whereNotNull('comments_synced_at')->pluck('external_id')->sort()->values();

    expect($first['posts'])->toBe(2);
    expect($done)->toHaveCount(2);

    $second = $sync->syncAll(2, backfill: true);
    $doneNow = AccountMedia::where('social_account_id', $account->id)
        ->whereNotNull('comments_synced_at')->pluck('external_id')->sort()->values();

    // The heart of it: run two covered posts the first run had NOT covered.
    expect($second['posts'])->toBe(2);
    expect($doneNow)->toHaveCount(4);
    expect($doneNow->intersect($done))->toHaveCount(2);
});

it('stops asking once every post has been covered', function () {
    $account = resumeAccount();
    fakeViewer();

    resumePost($account, 400, 'satusaja');

    $sync = app(InstagramCommentSync::class);

    expect($sync->syncAll(10, backfill: true)['posts'])->toBe(1);
    expect($sync->syncAll(10, backfill: true)['posts'])->toBe(0);
    expect($sync->progress()['remaining'])->toBe(0);
});

it('stamps a post that simply has no comments, so it cannot block the queue', function () {
    $account = resumeAccount();
    fakeViewer();

    $quiet = resumePost($account, 500, 'sepi');

    app(InstagramCommentSync::class)->syncAll(1, backfill: true);

    // Zero comments stored, but marked as done — otherwise it sits at the head
    // of the queue forever and every post behind it is unreachable.
    expect($quiet->fresh()->comments_synced_at)->not->toBeNull();
});

it('leaves a post unstamped when the fetch throws, so it is retried', function () {
    $account = resumeAccount();
    $post = resumePost($account, 500, 'gagal');

    Http::fake(fn () => throw new RuntimeException('jaringan putus'));

    $result = app(InstagramCommentSync::class)->syncAll(1, backfill: true);

    expect($result['failed'])->toBe(1);
    expect($post->fresh()->comments_synced_at)->toBeNull();
});

it('refresh mode still revisits recent posts rather than skipping them', function () {
    $account = resumeAccount();
    fakeViewer();

    $recent = resumePost($account, 2, 'baru');

    $sync = app(InstagramCommentSync::class);

    expect($sync->syncAll(10)['posts'])->toBe(1);
    // Already stamped, and still picked up: new comments land on recent posts,
    // so the refresh pass must not treat "done once" as "done forever".
    expect($sync->syncAll(10)['posts'])->toBe(1);
    expect($recent->fresh()->comments_synced_at)->not->toBeNull();
});

it('remembers where the insights walk stopped and resumes there', function () {
    $account = resumeAccount();

    $cursor = 'https://graph.instagram.com/v21.0/me/media?after=HALAMAN-DUA';

    Http::fake([
        '*/insights*' => Http::response(['data' => []], 400),
        '*/comments*' => Http::response(['data' => []]),
        '*/me/media*' => Http::response([
            'data' => [[
                'id' => 'lama-1',
                'media_type' => 'IMAGE',
                'permalink' => 'https://www.instagram.com/p/lama1/',
                // Years old: the backfill must ignore the age window entirely.
                'timestamp' => now()->subYears(4)->toIso8601String(),
            ]],
            'paging' => ['next' => $cursor],
        ]),
    ]);

    $result = app(InstagramInsightsSync::class)->sync($account, maxPages: 1, backfill: true);

    expect($result['media'])->toBe(1);
    expect($result['done'])->toBeFalse();
    expect($account->fresh()->media_cursor)->toBe($cursor);
    expect($account->fresh()->media_backfilled_at)->toBeNull();
});

it('marks the account finished when the walk runs out of pages', function () {
    $account = resumeAccount();
    $account->forceFill(['media_cursor' => 'https://graph.instagram.com/v21.0/me/media?after=TERAKHIR'])->saveQuietly();

    Http::fake([
        '*/insights*' => Http::response(['data' => []], 400),
        '*/comments*' => Http::response(['data' => []]),
        // No paging.next: the end of the account's history.
        '*/me/media*' => Http::response(['data' => []]),
    ]);

    $result = app(InstagramInsightsSync::class)->sync($account, maxPages: 5, backfill: true);

    expect($result['done'])->toBeTrue();
    expect($account->fresh()->media_cursor)->toBeNull();
    expect($account->fresh()->media_backfilled_at)->not->toBeNull();
});

it('does not touch the cursor on an ordinary refresh run', function () {
    $account = resumeAccount();
    $account->forceFill(['media_cursor' => 'https://graph.instagram.com/v21.0/me/media?after=SIMPAN-INI'])->saveQuietly();

    Http::fake([
        '*/insights*' => Http::response(['data' => []], 400),
        '*/comments*' => Http::response(['data' => []]),
        '*/me/media*' => Http::response(['data' => []]),
    ]);

    app(InstagramInsightsSync::class)->sync($account, maxPages: 1);

    // A refresh that overwrote the cursor would silently restart the backfill.
    expect($account->fresh()->media_cursor)->toBe('https://graph.instagram.com/v21.0/me/media?after=SIMPAN-INI');
});

it('does not move the marker when the network is down', function () {
    // The exact situation: a backfill started, the internet died mid-run.
    // Nothing must be recorded as covered, or those posts are skipped forever
    // once the connection comes back.
    $account = resumeAccount();

    foreach (range(1, 3) as $i) {
        resumePost($account, 300 + $i, 'putus'.$i);
    }

    $sync = app(InstagramCommentSync::class);
    $before = $sync->progress();

    // Not an exception from the client — the service catches those inside
    // fetch() and returns null, which is the path a real outage takes.
    Http::fake(fn () => throw new ConnectionException('Koneksi terputus'));

    $result = $sync->syncAll(3, backfill: true);

    expect($result['posts'])->toBe(0);
    expect($result['failed'])->toBe(3);

    // The marker has not moved: the same three posts are still waiting.
    expect($sync->progress())->toBe($before);
});

it('picks up exactly where it left off once the network returns', function () {
    $account = resumeAccount();

    foreach (range(1, 3) as $i) {
        resumePost($account, 300 + $i, 'pulih'.$i);
    }

    $sync = app(InstagramCommentSync::class);

    // One stub that can be brought back, rather than two Http::fake() calls:
    // a second fake is ADDED to the stub list, not swapped in, so the throwing
    // one would keep matching and the recovery would never be exercised.
    $mati = true;

    Http::fake(function () use (&$mati) {
        if ($mati) {
            throw new ConnectionException('koneksi terputus');
        }

        return Http::response(['code' => 0, 'data' => ['comment_count' => 0, 'comments' => []]]);
    });

    $sync->syncAll(3, backfill: true);

    expect($sync->progress()['remaining'])->toBe(3);

    // Connection back. No flag to reset, no repair command, no manual bookkeeping
    // — the next ordinary run simply finds them still unmarked and takes them.
    $mati = false;

    expect($sync->syncAll(3, backfill: true)['posts'])->toBe(3);
    expect($sync->progress()['remaining'])->toBe(0);
});
