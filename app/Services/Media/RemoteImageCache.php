<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Keeps a local copy of images that live behind expiring URLs.
 *
 * Instagram's CDN signs every image link and stamps an expiry into it (the
 * `oe=` parameter, a hex Unix timestamp). Roughly a fortnight later the link
 * returns "URL signature expired" and every avatar and thumbnail in the app
 * silently turns into a blank square — the database still holds a perfectly
 * valid-looking URL that no longer resolves.
 *
 * So the URL is only useful at the moment we receive it. This downloads the
 * bytes then, while the signature is still good, and everything afterwards
 * reads from our own disk.
 *
 * IMPORTANT: this cannot rescue links that have already expired. Those images
 * come back only on the next successful sync, when fresh URLs arrive.
 */
class RemoteImageCache
{
    /** Refuse anything larger — a profile picture is never megabytes. */
    private const MAX_BYTES = 5_242_880;   // 5 MB

    private const TIMEOUT = 15;

    /** Extensions we are willing to write, mapped from the response type. */
    private const TYPES = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /**
     * Fetch and store one image.
     *
     * @param  string  $folder  Bucket under the public disk, e.g. 'avatars'.
     * @param  string  $key     Stable identity — a handle or external id, NOT
     *                          the URL, so a changed avatar overwrites the old
     *                          file instead of piling up beside it.
     * @param  bool    $force   Re-download even if a copy already exists.
     * @return string|null      Path on the public disk, or null on any failure.
     */
    public function store(?string $url, string $folder, string $key, bool $force = false): ?string
    {
        if (blank($url) || ! str_starts_with($url, 'http')) {
            return null;
        }

        $disk = Storage::disk('public');
        $base = $this->path($folder, $key);

        if (! $force) {
            // Already have it under any extension? Then nothing to do.
            foreach (array_unique(self::TYPES) as $ext) {
                if ($disk->exists("{$base}.{$ext}")) {
                    return "{$base}.{$ext}";
                }
            }
        }

        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withHeaders(['Referer' => 'https://www.instagram.com/'])
                ->get($url);
        } catch (Throwable $e) {
            Log::info("Gagal mengunduh gambar {$folder}/{$key}: ".$e->getMessage());

            return null;
        }

        if ($response->failed()) {
            // An expired signature lands here — 403 with an XML error body.
            Log::info("Gambar {$folder}/{$key} tidak dapat diambil (".$response->status().').');

            return null;
        }

        $extension = self::TYPES[strtolower(explode(';', (string) $response->header('Content-Type'))[0])] ?? null;

        if ($extension === null) {
            Log::info("Gambar {$folder}/{$key} bukan tipe yang didukung: ".$response->header('Content-Type'));

            return null;
        }

        $body = $response->body();

        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return null;
        }

        $path = "{$base}.{$extension}";

        return $disk->put($path, $body) ? $path : null;
    }

    /** Remove a cached copy, e.g. when a contact is deleted. */
    public function forget(?string $path): void
    {
        if (filled($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Sharded path: cache/avatars/ab/abc123…
     *
     * The two-character shard keeps any one directory from holding tens of
     * thousands of files, which some filesystems handle badly.
     */
    private function path(string $folder, string $key): string
    {
        $hash = sha1($key);

        return sprintf('cache/%s/%s/%s', $folder, substr($hash, 0, 2), $hash);
    }
}
