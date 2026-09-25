<?php
use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('renders the monitoring index with the full metric strip', function () {
    $this->actingAs(admin())
        ->get(route('monitoring.index'))
        ->assertOk()
        ->assertSee('Following')
        ->assertSee('Total Interaksi')
        ->assertSee('Engagement')
        ->assertSee('Semua komentar');
});

it('renders the analytics page with the overview strip', function () {
    $this->actingAs(admin())
        ->get(route('analytics.index'))
        ->assertOk()
        ->assertSee('Total Reach')
        ->assertSee('Total Save')
        ->assertSee('Rata² Interaksi');
});

it('renders the all-comments page', function () {
    $this->actingAs(admin())
        ->get(route('monitoring.comments'))
        ->assertOk()
        ->assertSee('Semua Komentar');
});

it('computes current totals without dividing by zero on an empty account', function () {
    $svc = app(\App\Services\Analytics\MetricsComparison::class);
    $account = \App\Models\SocialAccount::create([
        'platform' => \App\Enums\SocialPlatform::Instagram,
        'name' => 'Kosong', 'external_id' => 'empty-tot',
        'access_token' => 'x', 'is_active' => true, 'media_count' => 0,
    ]);
    $totals = $svc->currentTotals($account);
    expect($totals['engagement_rate'])->toBeNull();
    expect($totals['avg_interactions'])->toBe(0);
    expect($totals)->toHaveKeys(['followers','follows','likes','comments','views','reach','saves','shares','interactions']);
});
