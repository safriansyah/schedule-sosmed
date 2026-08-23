<?php

namespace App\Services\Publishing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Looks up a public Instagram profile by username through the dolphinradar
 * viewer, so a commenter's handle can open a profile card in-app.
 *
 * Same unofficial-endpoint caveats as {@see InstagramCommentSync}: it can
 * change or vanish, so every failure returns null and is logged. Results are
 * cached briefly to avoid hammering the endpoint on repeat views.
 */
class InstagramProfileLookup
{
    private const CACHE_TTL_MINUTES = 360;   // 6 hours

    /**
     * @return array{
     *   username:string, full_name:?string, url:string, avatar_url:?string,
     *   is_private:bool, is_verified:bool, followers:int, following:int,
     *   media_count:int, bio:?string
     * }|null
     */
    public function find(string $username): ?array
    {
        $username = ltrim(trim($username), '@');

        if ($username === '') {
            return null;
        }

        return Cache::remember(
            "ig-profile:{$username}",
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn () => $this->fetch($username),
        );
    }

    /** @return array<string, mixed>|null */
    private function fetch(string $username): ?array
    {
        $base = rtrim((string) config('services.dolphinradar.base_url'), '/');

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json, text/plain, */*',
                'Tenantid' => (string) config('services.dolphinradar.tenant_id'),
                'Time-Zone' => (string) config('services.dolphinradar.timezone'),
                'Referer' => $base.'/instagram-viewer/post-comments',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                    .'(KHTML, like Gecko) Chrome/126.0 Safari/537.36',
            ])
                ->timeout(20)
                ->get("{$base}/api/ins/monitor/search/{$username}");
        } catch (Throwable $e) {
            Log::warning("Profile lookup error untuk {$username}: ".$e->getMessage());

            return null;
        }

        if ($response->failed() || (int) $response->json('code', -1) !== 0) {
            return null;
        }

        $row = $response->json('data.0');

        if (! is_array($row) || empty($row['success'])) {
            return null;
        }

        return [
            'username' => $row['media_name'] ?? $username,
            'full_name' => filled($row['full_name'] ?? null) ? $row['full_name'] : null,
            'url' => $row['media_url'] ?? "https://www.instagram.com/{$username}/",
            'avatar_url' => $row['profile_pic_url'] ?? null,
            'is_private' => (bool) ($row['is_private'] ?? false),
            'is_verified' => (bool) ($row['is_verified'] ?? false),
            'followers' => (int) ($row['follower_count'] ?? 0),
            'following' => (int) ($row['following_count'] ?? 0),
            'media_count' => (int) ($row['media_count'] ?? 0),
            'bio' => filled($row['profile'] ?? null) ? $row['profile'] : null,
        ];
    }
}
