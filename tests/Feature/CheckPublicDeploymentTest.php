<?php

/**
 * The audit for settings that only bite once the app is public.
 *
 * Every one of them is silent. The app runs perfectly with APP_DEBUG=true
 * behind a public domain, right until somebody triggers an exception and
 * Laravel prints the stack trace, the database password and the rest of .env
 * onto the page for whoever asked. Likewise SESSION_SECURE_COOKIE=true looks
 * like the correct choice for an HTTPS site and quietly locks every LAN user
 * out of logging in.
 *
 * So the test is that the command actually SAYS so, in each case.
 */

use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

/** The deployment described: public domain, everything else default. */
function publicConfig(array $overrides = []): void
{
    config(array_merge([
        'app.url' => 'https://utpkpinsight.my.id',
        'app.env' => 'production',
        'app.debug' => false,
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'session.secure' => null,
        'session.domain' => null,
    ], $overrides));
}

it('passes a correctly configured public deployment', function () {
    publicConfig();

    $this->artisan('app:check-public')
        ->expectsOutputToContain('Tidak ada masalah')
        // Cannot be checked from here, so it must at least be raised.
        ->expectsOutputToContain('Cloudflare Access')
        ->assertSuccessful();
});

it('refuses debug mode on a public site', function () {
    publicConfig(['app.debug' => true]);

    $this->artisan('app:check-public')
        ->expectsOutputToContain('sandi database')
        ->expectsOutputToContain('APP_DEBUG=false')
        ->assertFailed();
});

it('tolerates debug mode on a local-only deployment', function () {
    // Same setting, different verdict: on the office network it is a note, not
    // a problem. Flagging it as a failure there would train people to ignore
    // the command.
    publicConfig(['app.url' => 'http://10.15.10.221:5566', 'app.debug' => true, 'app.env' => 'local']);

    $this->artisan('app:check-public')
        ->expectsOutputToContain('aman untuk jaringan lokal')
        ->assertSuccessful();
});

it('catches the two session settings that break LAN login', function () {
    publicConfig(['session.secure' => true, 'session.domain' => 'utpkpinsight.my.id']);

    $this->artisan('app:check-public')
        ->expectsOutputToContain('TIDAK BISA LOGIN')
        ->expectsOutputToContain('SESSION_DOMAIN=null')
        ->assertFailed();
});

it('catches an empty application key', function () {
    publicConfig(['app.key' => '']);

    $this->artisan('app:check-public')
        ->expectsOutputToContain('key:generate')
        ->assertFailed();
});

it('catches a double slash in the media url', function () {
    publicConfig();
    config(['filesystems.disks.public.url' => 'https://utpkpinsight.my.id//storage']);
    Illuminate\Support\Facades\Storage::forgetDisk('public');

    $this->artisan('app:check-public')
        ->expectsOutputToContain('garis miring ganda')
        ->assertFailed();
});
