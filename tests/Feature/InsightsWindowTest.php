<?php

/**
 * The age window on accounts:sync-insights.
 *
 * Without it the walk paged to the end of the account — 2.200 posts, one
 * insights call each — and the monitoring grid filled with posts from years
 * back that nobody reports on. The window has to do two things: stop asking
 * for further pages, and not store the posts it decided to skip.
 *
 * Instagram returns the media list newest-first, which is the only reason
 * stopping at the first out-of-range post is safe; if that ever changed, the
 * "does not page past the window" expectation here is what would fail.
 */

use App\Enums\SocialPlatform;
use App\Models\{AccountMedia, SocialAccount};
use App\Services\Publishing\InstagramInsightsSync;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

/** An account with a token, so sync() gets as far as the HTTP call. */
function windowAccount(): SocialAccount
{
    return SocialAccount::create([
        'name' => 'Uji Jendela',
        'username' => 'uji_jendela_'.uniqid(),
        'platform' => SocialPlatform::Instagram,
        'external_id' => 'acc-'.uniqid(),
        'access_token' => 'token-uji',
        'is_active' => true,
    ]);
}

/** One media-list entry. */
function windowPost(string $id, string $timestamp): array
{
    return [
        'id' => $id,
        'caption' => 'Postingan '.$id,
        'media_type' => 'IMAGE',
        'media_product_type' => 'FEED',
        'permalink' => 'https://www.instagram.com/p/'.$id.'/',
        'timestamp' => $timestamp,
    ];
}

beforeEach(function () {
    // No insights, no comments: this test is about which posts get walked.
    Http::fake([
        '*/insights*' => Http::response(['data' => []], 400),
        '*/comments*' => Http::response(['data' => []]),
    ]);
});

it('stops at the first post older than the window', function () {
    config(['services.instagram.max_age_days' => 90]);

    Http::fake([
        '*/me/media*' => Http::response([
            'data' => [
                windowPost('baru-1', now()->subDays(3)->toIso8601String()),
                windowPost('baru-2', now()->subDays(80)->toIso8601String()),
                windowPost('lama-1', now()->subDays(200)->toIso8601String()),
                // Behind the cutoff in the same page — must not be stored even
                // though the walk has already read it off the wire.
                windowPost('lama-2', now()->subDays(400)->toIso8601String()),
            ],
            'paging' => ['next' => 'https://graph.instagram.com/v21.0/me/media?after=abc'],
        ]),
    ], );

    $result = app(InstagramInsightsSync::class)->sync(windowAccount());

    expect($result['media'])->toBe(2);
    expect(AccountMedia::whereIn('external_id', ['baru-1', 'baru-2'])->count())->toBe(2);
    expect(AccountMedia::whereIn('external_id', ['lama-1', 'lama-2'])->count())->toBe(0);
});

it('does not ask for the next page once the window is passed', function () {
    config(['services.instagram.max_age_days' => 60]);

    Http::fake([
        '*/me/media*' => Http::response([
            'data' => [
                windowPost('w-1', now()->subDays(1)->toIso8601String()),
                windowPost('w-2', now()->subDays(900)->toIso8601String()),
            ],
            'paging' => ['next' => 'https://graph.instagram.com/v21.0/me/media?after=halaman-dua'],
        ]),
    ]);

    app(InstagramInsightsSync::class)->sync(windowAccount());

    // The paging link was offered and declined: exactly one list call.
    // Counting list calls rather than all calls, because the per-post insights
    // and comments requests are not what this test is about.
    $listCalls = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), '/me/media'))
        ->count();

    expect($listCalls)->toBe(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'halaman-dua'));
});

it('walks everything when the window is switched off', function () {
    config(['services.instagram.max_age_days' => 0]);

    Http::fake([
        '*/me/media*' => Http::response([
            'data' => [
                windowPost('semua-1', now()->subDays(5)->toIso8601String()),
                windowPost('semua-2', now()->subYears(6)->toIso8601String()),
            ],
        ]),
    ]);

    $result = app(InstagramInsightsSync::class)->sync(windowAccount());

    expect($result['media'])->toBe(2);
    expect(AccountMedia::where('external_id', 'semua-2')->exists())->toBeTrue();
});

it('keeps a post whose timestamp is missing rather than ending the walk', function () {
    config(['services.instagram.max_age_days' => 90]);

    $noTimestamp = windowPost('tanpa-waktu', '');
    unset($noTimestamp['timestamp']);

    Http::fake([
        '*/me/media*' => Http::response([
            'data' => [
                $noTimestamp,
                windowPost('sesudahnya', now()->subDays(2)->toIso8601String()),
            ],
        ]),
    ]);

    $result = app(InstagramInsightsSync::class)->sync(windowAccount());

    // A data quirk must not cut the run short: both posts are stored.
    expect($result['media'])->toBe(2);
    expect(AccountMedia::where('external_id', 'sesudahnya')->exists())->toBeTrue();
});
