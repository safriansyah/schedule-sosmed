<?php

use App\Models\AccountMedia;
use App\Models\Contact;
use App\Models\Interaction;
use App\Services\Media\RemoteImageCache;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

/**
 * Instagram signs every image URL and stamps an expiry into it. Roughly a
 * fortnight later the link returns nothing and every avatar in the app turns
 * into a blank square, while the database still holds a URL that looks fine.
 *
 * So these tests care about one thing: are the bytes on our own disk?
 */
beforeEach(function () {
    Storage::fake('public');
});

function fakeJpeg(): string
{
    // Enough of a JPEG header to be a plausible body; the cache trusts the
    // Content-Type header, not the bytes.
    return "\xFF\xD8\xFF\xE0".str_repeat('x', 512);
}

it('downloads an image and stores it on the public disk', function () {
    Http::fake(['*' => Http::response(fakeJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);

    $path = app(RemoteImageCache::class)->store('https://cdn.example/pic.jpg', 'avatars', 'budi');

    expect($path)->not->toBeNull()
        ->and($path)->toEndWith('.jpg');

    Storage::disk('public')->assertExists($path);
});

it('keys on identity, not on the URL', function () {
    Http::fake(['*' => Http::response(fakeJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);

    $cache = app(RemoteImageCache::class);

    // The same person, but Instagram handed us a freshly signed URL. It must
    // land on the same file rather than accumulating a copy per signature.
    $first = $cache->store('https://cdn.example/pic.jpg?sig=aaa', 'avatars', 'budi');
    $second = $cache->store('https://cdn.example/pic.jpg?sig=bbb', 'avatars', 'budi', force: true);

    expect($second)->toBe($first);
});

it('does not re-download something it already has', function () {
    Http::fake(['*' => Http::response(fakeJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);

    $cache = app(RemoteImageCache::class);
    $cache->store('https://cdn.example/pic.jpg', 'avatars', 'budi');
    $cache->store('https://cdn.example/pic.jpg', 'avatars', 'budi');

    Http::assertSentCount(1);
});

it('returns null rather than throwing when the signature has expired', function () {
    // What an expired Instagram link actually does.
    Http::fake(['*' => Http::response('URL signature expired', 403)]);

    expect(app(RemoteImageCache::class)->store('https://cdn.example/old.jpg', 'avatars', 'lama'))
        ->toBeNull();
});

it('refuses anything that is not an image', function () {
    Http::fake(['*' => Http::response('<html>login</html>', 200, ['Content-Type' => 'text/html'])]);

    expect(app(RemoteImageCache::class)->store('https://cdn.example/page', 'avatars', 'x'))
        ->toBeNull();

    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

it('ignores blank and non-http values', function () {
    Http::fake();

    $cache = app(RemoteImageCache::class);

    expect($cache->store(null, 'avatars', 'x'))->toBeNull()
        ->and($cache->store('', 'avatars', 'x'))->toBeNull()
        ->and($cache->store('data:image/png;base64,AAAA', 'avatars', 'x'))->toBeNull();

    Http::assertNothingSent();
});

/* -----------------------------------------------------------------
 | The models must prefer the local copy
 * ----------------------------------------------------------------- */

it('serves the local copy when there is one', function () {
    $interaction = new Interaction([
        'author_avatar' => 'https://cdn.example/expired.jpg',
        'author_avatar_path' => 'cache/avatars/ab/abc.jpg',
    ]);

    expect($interaction->avatar())->toContain('cache/avatars/ab/abc.jpg')
        ->and($interaction->avatar())->not->toContain('cdn.example');
});

it('falls back to the remote URL for rows stored before caching existed', function () {
    $interaction = new Interaction(['author_avatar' => 'https://cdn.example/still-live.jpg']);

    expect($interaction->avatar())->toBe('https://cdn.example/still-live.jpg');
});

it('applies the same rule to contacts and posts', function () {
    $contact = new Contact(['avatar_url' => 'https://cdn/x.jpg', 'avatar_path' => 'cache/avatars/c/c.jpg']);
    $media = new AccountMedia(['thumbnail_url' => 'https://cdn/y.jpg', 'thumbnail_path' => 'cache/thumbnails/d/d.jpg']);

    expect($contact->avatar())->toContain('cache/avatars/c/c.jpg')
        ->and($media->thumbnail())->toContain('cache/thumbnails/d/d.jpg');
});

it('shares one implementation with rows that are not models', function () {
    // Analytics builds its rows with DB::table for speed, so they have no
    // accessors — the decision must not be duplicated in a Blade template.
    expect(AccountMedia::imageUrl('cache/thumbnails/a/a.jpg', 'https://cdn/z.jpg'))
        ->toContain('cache/thumbnails/a/a.jpg');

    expect(AccountMedia::imageUrl(null, 'https://cdn/z.jpg'))
        ->toBe('https://cdn/z.jpg');
});
