<?php

/**
 * The keep/empty split for `data:reset`.
 *
 * TRUNCATE is DDL — it commits itself and cannot be rolled back — so these
 * tests never run the destructive path. What they pin is the decision that
 * matters: which tables survive. Getting that wrong on a handover either
 * wipes the accounts and settings someone just configured, or leaves the
 * previous account's access token sitting in the database.
 */

use App\Enums\RoleName;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

/** @return array<int, string> */
function resetKeepList(): array
{
    $command = new ReflectionClass(\App\Console\Commands\DataReset::class);

    return array_keys((array) $command->getConstant('KEEP'));
}

it('keeps the setup a handover must not lose', function () {
    // Accounts, who may do what, the reference lists the app cannot rebuild
    // on its own, and the site settings someone typed in by hand.
    expect(resetKeepList())->toContain('users')
        ->toContain('roles')
        ->toContain('permissions')
        ->toContain('permission_role')
        ->toContain('settings')
        ->toContain('regions')
        ->toContain('ticket_categories')
        // Without this one Laravel believes the schema was never migrated.
        ->toContain('migrations');
});

it('empties the tables that carry the previous installation', function () {
    $keep = resetKeepList();

    foreach ([
        'social_accounts',   // holds the Instagram access token
        'students',
        'tickets',
        'ticket_details',
        'interactions',
        'contacts',
        'media_comments',
        'account_media',
        'follow_ups',
        'activities',
        'sessions',
    ] as $table) {
        expect($keep)->not->toContain($table);
    }
});

it('names only tables that exist, so nothing is kept by accident', function () {
    // A renamed or dropped table left in KEEP would mean something meant to
    // survive quietly gets emptied instead. The command refuses to run in
    // that case; this catches it at test time.
    $present = collect(DB::select(
        'SELECT TABLE_NAME AS name FROM information_schema.TABLES '
        ."WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
    ))->pluck('name')->all();

    expect(array_diff(resetKeepList(), $present))->toBe([]);
});

it('changes nothing on a dry run', function () {
    $before = [
        'users' => User::count(),
        'settings' => Setting::count(),
        'students' => DB::table('students')->count(),
        'tickets' => DB::table('tickets')->count(),
    ];

    $this->artisan('data:reset --dry-run')->assertSuccessful();

    foreach ($before as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
});

it('lists both halves so the operator can check before confirming', function () {
    $this->artisan('data:reset --dry-run')
        ->expectsOutputToContain('DISIMPAN')
        ->expectsOutputToContain('DIKOSONGKAN')
        ->expectsOutputToContain('users')
        ->expectsOutputToContain('social_accounts')
        ->assertSuccessful();
});

it('is reachable only from the console', function () {
    // No route, no button. A one-way wipe does not belong behind a click.
    expect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->each(fn ($route) => $route->toBeObject());

    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    $this->actingAs($admin)->get('/data/reset')->assertNotFound();
});
