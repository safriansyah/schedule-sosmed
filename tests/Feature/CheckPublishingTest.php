<?php

/**
 * The preflight that catches the failure nobody can see from inside the app.
 *
 * Instagram does not receive the image — it receives a URL, and its own servers
 * download the file. On an intranet deployment that URL is a private address,
 * so the container is never created and every scheduled post fails. The
 * scheduler is running, the queue is healthy, the token is valid, and content
 * still never publishes.
 *
 * The check therefore has to key on APP_URL, and it has to be WRONG in the safe
 * direction: warning about a working setup is noise, but staying quiet about a
 * broken one is how the whole feature fails silently.
 */

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

beforeEach(function () {
    // Read-only profile call; the command never creates a container.
    Http::fake(['*/me*' => Http::response(['account_type' => 'BUSINESS', 'username' => 'uji'])]);
});

/** Point the public disk at a given host, the way APP_URL does in production. */
function withAppUrl(string $url): void
{
    config(['app.url' => $url, 'filesystems.disks.public.url' => rtrim($url, '/').'/storage']);
}

it('refuses a localhost url', function () {
    withAppUrl('http://localhost:5566');

    $this->artisan('content:check-publish')
        ->expectsOutputToContain('ALAMAT LOKAL')
        ->assertFailed();
});

it('refuses a private LAN address', function () {
    // The real intranet case: reachable on the office WiFi, invisible to Instagram.
    foreach (['http://10.15.10.221:5566', 'http://192.168.1.50', 'http://172.16.4.4'] as $url) {
        withAppUrl($url);

        $this->artisan('content:check-publish')
            ->expectsOutputToContain('ALAMAT LOKAL')
            ->assertFailed();
    }
});

it('accepts a public domain', function () {
    withAppUrl('https://sosmed.utpangkalpinang.ac.id');

    $this->artisan('content:check-publish')
        ->expectsOutputToContain('alamat publik')
        ->assertSuccessful();
});

it('accepts a public IP', function () {
    withAppUrl('http://103.11.22.33');

    $this->artisan('content:check-publish')
        ->expectsOutputToContain('alamat publik')
        ->assertSuccessful();
});

it('names the intranet workarounds rather than just refusing', function () {
    withAppUrl('http://10.15.10.221:5566');

    // Read at a terminal the night before a demo: a verdict with no way
    // forward is worse than none.
    $this->artisan('content:check-publish')
        ->expectsOutputToContain('Terbitkan manual')
        ->expectsOutputToContain('Cloudflare Tunnel')
        ->assertFailed();
});
