<?php
use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

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

    expect(session('toast')['message'])->toContain('Sinkron selesai');
});

it('gives every role access to trigger a sync', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    foreach (RoleName::cases() as $role) {
        $this->actingAs(User::withRole($role)->firstOrFail())
            ->post(route('sync.now'))->assertRedirect();
    }
});
