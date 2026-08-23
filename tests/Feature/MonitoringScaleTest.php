<?php
use App\Enums\RoleName;
use Illuminate\Support\Str;
use App\Models\{AccountMedia, MediaMetric, SocialAccount, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

function seedPosts(SocialAccount $account, int $count): void {
    $now = now();
    $media = [];
    for ($i = 0; $i < $count; $i++) {
        $media[] = [
            'id' => (string) Str::uuid(), 'social_account_id' => $account->id,
            'external_id' => "big-{$i}-".uniqid(), 'caption' => "Postingan nomor {$i}",
            'media_type' => 'IMAGE', 'product_type' => $i % 5 === 0 ? 'REELS' : 'FEED',
            'posted_at' => $now->copy()->subDays($i % 300),
            'created_at' => $now, 'updated_at' => $now,
        ];
    }
    foreach (array_chunk($media, 200) as $chunk) AccountMedia::insert($chunk);

    $metrics = [];
    foreach ($media as $i => $m) {
        $metrics[] = [
            'id' => (string) Str::uuid(), 'account_media_id' => $m['id'],
            'captured_on' => today(), 'captured_at' => $now,
            'likes' => $i * 3, 'comments' => $i, 'views' => $i * 10, 'reach' => $i * 5,
            'saves' => 0, 'shares' => 0, 'interactions' => $i * 4,
            'created_at' => $now, 'updated_at' => $now,
        ];
    }
    foreach (array_chunk($metrics, 200) as $chunk) MediaMetric::insert($chunk);
}

it('keeps the monitoring page fast with a thousand posts', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();
    $account = SocialAccount::create([
        'platform' => 'instagram', 'name' => 'Akun Besar', 'external_id' => 'big-'.uniqid(),
        'access_token' => 'x', 'is_active' => true,
    ]);

    $count = function () use ($admin, $account) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('monitoring.index', ['account' => $account->id]))->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    seedPosts($account, 50);
    $small = $count();

    seedPosts($account, 950);
    $large = $count();

    $this->actingAs($admin)
        ->get(route('monitoring.index', ['account' => $account->id]))
        ->assertOk()
        ->assertSee('1,000');   // total shown

    // Only one page of 12 is rendered and the latest snapshot is joined in
    // SQL, so 20x the data must not cost more queries.
    expect($large)->toBeLessThanOrEqual($small)
        ->and($large)->toBeLessThan(30);
});

it('searches, filters and sorts a large account', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();
    $account = SocialAccount::create([
        'platform' => 'instagram', 'name' => 'Akun Filter', 'external_id' => 'flt-'.uniqid(),
        'access_token' => 'x', 'is_active' => true,
    ]);

    seedPosts($account, 300);

    $base = ['account' => $account->id];

    // Search narrows the set
    $this->actingAs($admin)->get(route('monitoring.index', $base + ['q' => 'nomor 42']))
        ->assertOk()->assertSee('Postingan nomor 42');

    // Type filter: 300 posts, every 5th is a Reel => 60
    $this->actingAs($admin)->get(route('monitoring.index', $base + ['type' => 'REELS']))
        ->assertOk()->assertSee('(60)');

    // Sorting by likes puts the highest first (post 299 => 897 likes).
    // This also guards the stat aliases: a broken select renders 0 here.
    $this->actingAs($admin)->get(route('monitoring.index', $base + ['sort' => 'likes']))
        ->assertOk()->assertSee('897');
});
