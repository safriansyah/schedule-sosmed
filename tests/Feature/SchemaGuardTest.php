<?php

/**
 * The guard that stops a command when the database is behind the code.
 *
 * The accident it exists for: the app gets moved between machines by copying
 * folders, `database/` is left behind, and the backfill then reads a column
 * that does not exist. Eloquent returns null for a missing column rather than
 * complaining, so the run made several hundred Instagram API calls before
 * dying on the save with "Unknown column 'media_cursor'" — quota spent, and a
 * message that named a column instead of a missing migration.
 *
 * DDL commits itself, so a test cannot drop a column inside a transaction and
 * put it back. The detection is therefore tested directly, and the wiring is
 * tested by asserting the commands are clean against the real schema.
 */

use App\Support\SchemaGuard;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('spots a column that is not there', function () {
    $missing = SchemaGuard::missing([
        'social_accounts.media_cursor',              // exists
        'social_accounts.kolom_yang_tidak_ada',      // does not
    ]);

    expect($missing)->toBe(['social_accounts.kolom_yang_tidak_ada']);
});

it('spots a table that is not there', function () {
    expect(SchemaGuard::missing(['tabel_hantu.apa_saja']))->toBe(['tabel_hantu.apa_saja']);
});

it('says nothing when the schema is up to date', function () {
    // The exact columns the two backfill commands check before their first
    // API call. If a migration is ever renamed or reverted, this fails here
    // rather than in production at call number three hundred.
    expect(SchemaGuard::missing([
        'social_accounts.media_cursor',
        'social_accounts.media_backfilled_at',
        'account_media.comments_synced_at',
    ]))->toBe([]);
});

it('names the fix, not just the fault', function () {
    $message = SchemaGuard::explain(['social_accounts.media_cursor']);

    // Read at a terminal on another machine by someone who does not have this
    // file open, so it has to carry the command itself.
    expect($message)->toContain('social_accounts.media_cursor');
    expect($message)->toContain('php artisan migrate --force');
    expect($message)->toContain('database/migrations');
});

it('lets the backfill commands run when the schema is current', function () {
    // Not the sync itself — just that the guard does not block. Both commands
    // stop at the guard and return FAILURE when a column is missing, so
    // reaching past it is the thing being asserted.
    $this->artisan('accounts:sync-comments', ['--lanjut' => true, '--posts' => 0])
        ->assertSuccessful();
});
