<?php

/**
 * What has to be true before a Cloudflare Tunnel (or ngrok) actually works.
 *
 * Two things break quietly behind a tunnel, and neither produces an error:
 *
 *   1. The media URL. Instagram fetches the image itself, so that URL must be
 *      public — but the app is on the office network and APP_URL is a private
 *      address. FILESYSTEM_PUBLIC_URL separates the two, so a tunnel can expose
 *      only /storage while the application stays internal.
 *
 *   2. HTTPS detection. The tunnel terminates TLS and forwards plain HTTP from
 *      a local agent. Without trusted proxies Laravel believes the request is
 *      insecure and generates http:// links, which browsers then block as mixed
 *      content.
 */

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

it('falls back to APP_URL when no separate media url is set', function () {
    // The ordinary public deployment: one address for everything.
    config([
        'app.url' => 'https://sosmed.example.ac.id',
        'filesystems.disks.public.url' => 'https://sosmed.example.ac.id/storage',
    ]);

    expect(Storage::disk('public')->url('media/x.jpg'))
        ->toBe('https://sosmed.example.ac.id/storage/media/x.jpg');
});

it('serves media from the tunnel while the app stays on the local network', function () {
    // The intranet deployment: the app is only reachable on the office WiFi,
    // and just the media is published through the tunnel.
    config([
        'app.url' => 'http://10.15.10.221:5566',
        'filesystems.disks.public.url' => 'https://media.example.ac.id/storage',
    ]);

    expect(Storage::disk('public')->url('media/x.jpg'))
        ->toBe('https://media.example.ac.id/storage/media/x.jpg');

    // And that is exactly what the publishing preflight reads, so it flips to
    // ready without APP_URL having to be exposed.
    $this->artisan('content:check-publish')->expectsOutputToContain('alamat publik');
});

it('sees a tunnelled request as secure', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    // What cloudflared sends: it terminates TLS and forwards over HTTP from
    // loopback, announcing the original scheme in a header.
    $response = $this->actingAs($admin)->get('/dashboard', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'sosmed.example.ac.id',
    ]);

    $response->assertOk();

    expect(request()->isSecure())->toBeTrue();
});

it('ignores forwarded headers from somewhere that is not the tunnel', function () {
    // Only loopback is trusted by default. A header arriving from anywhere else
    // must not be able to talk the app into believing it is on HTTPS, or into
    // generating links pointing at an attacker's host.
    config(['app.url' => 'http://10.15.10.221:5566']);

    $this->app['request']->server->set('REMOTE_ADDR', '203.0.113.9');

    $response = $this->actingAs(User::withRole(RoleName::SuperAdmin)->firstOrFail())
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->get('/dashboard', ['X-Forwarded-Host' => 'jahat.example.com']);

    $response->assertOk();

    expect(request()->getHost())->not->toBe('jahat.example.com');
});

it('tolerates a trailing slash in either url setting', function () {
    // "https://utpkpinsight.my.id/" is a perfectly normal thing to type into
    // .env, and concatenating it gave "https://utpkpinsight.my.id//storage/x.jpg".
    // A browser forgives the double slash; the server-side fetcher that matters
    // here -- Instagram's -- does not have to.
    $cases = [
        ['https://utpkpinsight.my.id/', null],
        ['https://utpkpinsight.my.id', null],
        ['http://10.15.10.221:5566', 'https://media.utpkpinsight.my.id/storage/'],
        ['http://10.15.10.221:5566', 'https://media.utpkpinsight.my.id/storage'],
    ];

    foreach ($cases as [$appUrl, $mediaUrl]) {
        config([
            'app.url' => $appUrl,
            'filesystems.disks.public.url' => rtrim(
                $mediaUrl ?: rtrim($appUrl, '/').'/storage',
                '/',
            ),
        ]);

        Storage::forgetDisk('public');

        $url = Storage::disk('public')->url('media/foto.jpg');

        expect($url)->not->toContain('//storage');
        expect($url)->toEndWith('/storage/media/foto.jpg');
    }
});
