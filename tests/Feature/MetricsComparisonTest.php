<?php
use App\Enums\SocialPlatform;
use App\Models\{AccountMedia, AccountMetric, MediaMetric, SocialAccount};
use App\Services\Analytics\MetricsComparison;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

function seedHistory(): SocialAccount {
    $account = SocialAccount::create([
        'platform' => SocialPlatform::Instagram, 'name' => 'Cmp IG',
        'external_id' => 'cmp-'.uniqid(), 'access_token' => 'x', 'is_active' => true,
    ]);

    $media = AccountMedia::create([
        'social_account_id' => $account->id, 'external_id' => 'm-'.uniqid(),
        'media_type' => 'IMAGE', 'product_type' => 'FEED', 'posted_at' => now()->subDays(20),
    ]);

    // Lifetime totals climbing by a known amount each day.
    //   older days : +10 likes/day  -> previous 7-day window gains 80
    //   recent days : +20 likes/day  -> current  7-day window gains 140
    $likes = 0;
    for ($d = 14; $d >= 0; $d--) {
        $step = $d >= 8 ? 10 : 20;
        if ($d < 14) $likes += $step;

        $day = today()->subDays($d);

        MediaMetric::create([
            'account_media_id' => $media->id, 'captured_on' => $day,
            'likes' => $likes, 'comments' => 0, 'views' => $likes * 2,
            'reach' => 0, 'saves' => 0, 'shares' => 0, 'interactions' => $likes,
        ]);

        AccountMetric::create([
            'social_account_id' => $account->id, 'captured_on' => $day,
            'followers' => 1000 + intdiv($likes, 10), 'follows' => 10, 'media_count' => 1,
        ]);
    }

    return $account;
}

it('computes weekly growth and the percentage versus the previous week', function () {
    $account = seedHistory();

    $result = app(MetricsComparison::class)->forAccount($account, 'week');

    expect($result['period']['label'])->toBe('7 hari');

    // Current 7 days gained 140 likes, the 7 before that gained 80 → +75%.
    expect($result['metrics']['likes']['current'])->toBe(140)
        ->and($result['metrics']['likes']['previous'])->toBe(80)
        ->and($result['metrics']['likes']['change'])->toBe(60)
        ->and($result['metrics']['likes']['percent'])->toBe(75.0)
        ->and($result['metrics']['views']['current'])->toBe(280);
});

it('tracks follower growth separately from the running total', function () {
    $account = seedHistory();

    $result = app(MetricsComparison::class)->forAccount($account, 'week');

    expect($result['metrics']['followers']['current'])->toBe(14)      // gained this week
        ->and($result['metrics']['followers']['previous'])->toBe(8)   // gained the week before
        ->and($result['metrics']['followers']['percent'])->toBe(75.0)
        ->and($result['metrics']['followers_total']['current'])->toBe(1022);
});

it('supports every preset period and a custom range', function () {
    $account = seedHistory();
    $service = app(MetricsComparison::class);

    foreach (array_keys(MetricsComparison::PERIODS) as $period) {
        $r = $service->forAccount($account, $period);
        expect($r['metrics'])->toHaveKeys(['likes', 'views', 'reach', 'followers']);
    }

    $custom = $service->forAccount($account, 'custom', today()->subDays(3), today());
    expect($custom['period']['label'])->toBe('Kustom')
        ->and($custom['metrics']['likes']['current'])->toBe(80);   // 4 days x 20
});

it('reports no baseline instead of dividing by zero', function () {
    $account = SocialAccount::create([
        'platform' => SocialPlatform::Instagram, 'name' => 'Kosong',
        'external_id' => 'empty-'.uniqid(), 'access_token' => 'x', 'is_active' => true,
    ]);

    $result = app(MetricsComparison::class)->forAccount($account, 'week');

    expect($result['metrics']['likes']['current'])->toBe(0)
        ->and($result['metrics']['likes']['percent'])->toBeNull();
});
