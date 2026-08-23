<?php
use App\Enums\RoleName;
use App\Models\{AccountMedia, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('shows the monitoring page with real synced posts', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    $this->actingAs($admin)->get(route('monitoring.index'))
        ->assertOk()
        ->assertSee('Monitoring')
        ->assertSee('Bangka Crew Reborn')
        ->assertSee('Postingan');
});

it('opens a post and shows its metrics', function () {
    $media = AccountMedia::with('metrics')->firstOrFail();
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    $this->actingAs($admin)->get(route('monitoring.show', $media))
        ->assertOk()
        ->assertSee('Kenaikan')
        ->assertSee('Reach');
});

it('lets admin and director monitor', function () {
    foreach ([RoleName::SuperAdmin, RoleName::Director] as $role) {
        $this->actingAs(User::withRole($role)->firstOrFail())
            ->get(route('monitoring.index'))->assertOk();
    }
});

it('accepts every period including a custom range', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    foreach (['today', '3days', 'week', 'month', 'year'] as $period) {
        $this->actingAs($admin)->get(route('monitoring.index', ['period' => $period]))->assertOk();
    }

    $this->actingAs($admin)->get(route('monitoring.index', [
        'period' => 'custom',
        'from' => today()->subDays(10)->toDateString(),
        'to' => today()->toDateString(),
    ]))->assertOk()->assertSee('Kustom');
});
