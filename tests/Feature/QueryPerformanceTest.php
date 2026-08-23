<?php
use App\Enums\{ContentStatus, RoleName, SocialPlatform};
use App\Models\{Content, SocialAccount, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

/** Seed enough rows that an N+1 would show up clearly. */
function seedContents(int $n, ContentStatus $status): void {
    $creative = User::withRole(RoleName::Creative)->firstOrFail();
    $account = SocialAccount::firstOrCreate(
        ['platform' => SocialPlatform::Instagram, 'external_id' => 'perf-test'],
        ['name' => 'Perf IG', 'username' => 'perf', 'is_active' => true],
    );

    for ($i = 0; $i < $n; $i++) {
        $c = Content::create([
            'title' => "Perf {$i}", 'status' => $status, 'created_by' => $creative->id,
            'scheduled_at' => now()->addDays($i),
        ]);
        $c->media()->create(['type' => 'image', 'disk' => 'public', 'path' => "p{$i}.jpg", 'size' => 1]);
        $c->schedules()->create([
            'social_account_id' => $account->id, 'scheduled_at' => now()->addDays($i), 'status' => $status,
        ]);
    }
}

function countQueries(callable $fn): int {
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    return $count;
}

it('keeps the content list query count flat as rows grow', function () {
    $user = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    seedContents(3, ContentStatus::Draft);
    $few = countQueries(fn () => $this->actingAs($user)->get(route('contents.index'))->assertOk());

    seedContents(12, ContentStatus::Draft);
    $many = countQueries(fn () => $this->actingAs($user)->get(route('contents.index'))->assertOk());

    // Eager loading means more rows must not mean more queries.
    expect($many)->toBeLessThanOrEqual($few + 1)
        ->and($many)->toBeLessThan(20);
});

it('keeps the approval queue query count flat', function () {
    $curator = User::withRole(RoleName::Curator)->firstOrFail();

    seedContents(3, ContentStatus::WaitingApproval);
    $few = countQueries(fn () => $this->actingAs($curator)->get(route('approvals.index'))->assertOk());

    seedContents(12, ContentStatus::WaitingApproval);
    $many = countQueries(fn () => $this->actingAs($curator)->get(route('approvals.index'))->assertOk());

    expect($many)->toBeLessThanOrEqual($few + 1);
});

it('keeps the activity log query count flat', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();
    seedContents(10, ContentStatus::Draft);

    $count = countQueries(fn () => $this->actingAs($admin)->get(route('activities.index'))->assertOk());
    expect($count)->toBeLessThan(15);
});
