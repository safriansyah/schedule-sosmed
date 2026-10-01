<?php

/**
 * The app reachable two ways at once: a public domain through a Cloudflare
 * Tunnel, and the LAN address directly.
 *
 * Staff on the office WiFi keep using http://10.15.10.221:5566; everyone else,
 * and Instagram when it fetches media, uses https://utpkpinsight.my.id. Both
 * have to work from ONE deployment, which turns on three things that are easy
 * to get wrong:
 *
 *   - links must follow the address the visitor actually used, not APP_URL,
 *     or the domain would hand out LAN links and vice versa;
 *   - the session cookie must not be marked Secure, or logging in over plain
 *     HTTP on the LAN silently fails — the browser refuses to send the cookie
 *     back and every request looks logged out;
 *   - SESSION_DOMAIN must stay null, or the cookie is scoped to the domain and
 *     is never sent to the bare IP at all.
 */

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

const DOMAIN = 'https://utpkpinsight.my.id';
const LAN = 'http://10.15.10.221:5566';

beforeEach(function () {
    // The deployment being described: APP_URL is the public domain, and media
    // falls back to it because the whole app is tunnelled.
    config([
        'app.url' => DOMAIN,
        'filesystems.disks.public.url' => DOMAIN.'/storage',
        'session.domain' => null,
        'session.secure' => null,
    ]);

    Storage::forgetDisk('public');
});

it('serves the same page on the domain and on the LAN address', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    foreach ([DOMAIN, LAN] as $base) {
        $this->actingAs($admin)->get($base.'/dashboard')->assertOk();
    }
});

it('builds links from the address the visitor used', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    foreach ([DOMAIN => 'utpkpinsight.my.id', LAN => '10.15.10.221'] as $base => $expectedHost) {
        $html = $this->actingAs($admin)->get($base.'/dashboard')->getContent();

        preg_match_all('#href="(https?://[^"]+)"#', $html, $matches);

        $hosts = collect($matches[1])
            ->map(fn ($url) => parse_url($url, PHP_URL_HOST))
            ->unique()
            // Web fonts are a third party and legitimately point elsewhere.
            ->reject(fn ($host) => str_contains((string) $host, 'bunny.net'))
            ->values();

        expect($hosts->all())->toBe([$expectedHost]);
    }
});

it('does not mark the session cookie Secure, so LAN login still works', function () {
    // Secure cookies are only ever sent over HTTPS. Setting
    // SESSION_SECURE_COOKIE=true would make the domain work and lock every
    // LAN user out of logging in, with no error to explain it.
    expect(config('session.secure'))->toBeNull();
    expect(config('session.domain'))->toBeNull();

    // A LAN visit is plain HTTP. Spelled out, because a bare '/login' would
    // be resolved against APP_URL — the https:// domain — and test that.
    $response = $this->post('http://192.168.1.10:5566/login', [
        'email' => User::withRole(RoleName::SuperAdmin)->firstOrFail()->email,
        'password' => 'password',
    ]);

    $cookie = collect($response->headers->getCookies())
        ->first(fn ($c) => str_contains($c->getName(), 'session'));

    expect($cookie)->not->toBeNull();
    expect($cookie->isSecure())->toBeFalse();
    expect($cookie->getDomain())->toBeEmpty();
});

it('serves media from the domain even to a LAN visitor', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    // Worth knowing rather than discovering: with one public APP_URL, images
    // are addressed on the domain for everyone. A LAN visitor therefore needs
    // the tunnel up to see thumbnails, even though the page itself is local.
    $html = $this->actingAs($admin)->get(LAN.'/monitoring')->getContent();

    preg_match('#(https?://[^"\s]*?/storage/[^"\s]+)#', $html, $m);

    expect($m[1] ?? '')->toStartWith(DOMAIN.'/storage/');
});

it('tells a Windows server how to start the scheduler, even in production', function () {
    // APP_ENV=production is correct once the app is reachable from the
    // internet, but it used to flip this advice to "add a line to cron" -- on
    // a machine with no crontab. The reader then went looking for a daemon
    // Windows does not have while the scheduler stayed down.
    config(['app.env' => 'production']);

    $advice = App\Services\SystemHealth::howToStart();

    if (PHP_OS_FAMILY === 'Windows') {
        expect($advice)->toContain('composer run dev:lan');
        expect($advice)->not->toContain('cron');
    } else {
        expect($advice)->toContain('schedule:run');
    }
});
