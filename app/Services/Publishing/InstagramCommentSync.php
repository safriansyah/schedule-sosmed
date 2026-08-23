<?php

namespace App\Services\Publishing;

use App\Enums\SocialPlatform;
use App\Models\AccountMedia;
use App\Models\MediaComment;
use App\Models\SocialAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads the comments on our own posts through the public dolphinradar viewer.
 *
 * The official Instagram Graph API withholds comment text until the app has
 * Advanced Access (App Review), which is bureaucratic overkill for monitoring
 * our own posts. This viewer resolves a post's shortcode from its permalink
 * and returns the public comments — the same ones anyone sees on the post.
 *
 * It is an UNOFFICIAL, undocumented endpoint: it can change, rate-limit, or
 * disappear at any time. Every failure is therefore swallowed and logged so a
 * bad response never breaks a sync run or the dashboard.
 */
class InstagramCommentSync
{
    /**
     * @param  int|null  $maxPostsOverride  Cap posts per account (e.g. a smaller
     *                                       number for the synchronous manual button).
     * @return array{posts:int, comments:int, failed:int}
     */
    public function syncAll(?int $maxPostsOverride = null): array
    {
        $totals = ['posts' => 0, 'comments' => 0, 'failed' => 0];

        $maxAge = (int) config('services.dolphinradar.max_age_days');
        $maxPosts = $maxPostsOverride ?? (int) config('services.dolphinradar.max_posts');

        $accounts = SocialAccount::active()
            ->where('platform', SocialPlatform::Instagram->value)
            ->get();

        foreach ($accounts as $account) {
            $query = AccountMedia::where('social_account_id', $account->id)
                ->whereNotNull('permalink')
                ->orderByDesc('posted_at');

            // Comments arrive mostly on recent posts — don't waste calls on old ones.
            if ($maxAge > 0) {
                $query->where(fn ($q) => $q
                    ->whereNull('posted_at')
                    ->orWhere('posted_at', '>=', now()->subDays($maxAge)));
            }

            foreach ($query->limit(max(1, $maxPosts))->get() as $media) {
                try {
                    $totals['comments'] += $this->syncMedia($media);
                    $totals['posts']++;
                } catch (Throwable $e) {
                    $totals['failed']++;
                    Log::warning("Gagal sinkron komentar media {$media->external_id}: ".$e->getMessage());
                }
            }
        }

        return $totals;
    }

    /** Fetch and store the comments for a single post. Returns the count stored. */
    public function syncMedia(AccountMedia $media): int
    {
        $shortcode = $this->shortcodeFrom($media->permalink);

        if ($shortcode === null) {
            return 0;
        }

        $comments = $this->fetch($shortcode);

        if ($comments === null) {
            return 0;
        }

        $stored = 0;

        foreach ($comments as $comment) {
            $pk = $comment['pk'] ?? null;

            if (blank($pk)) {
                continue;
            }

            $user = $comment['media_user_dto'] ?? [];

            MediaComment::updateOrCreate(
                ['account_media_id' => $media->id, 'external_id' => (string) $pk],
                [
                    'username' => $user['username'] ?? null,
                    'full_name' => filled($user['full_name'] ?? null) ? $user['full_name'] : null,
                    'avatar_url' => $user['profile_pic_url'] ?? null,
                    'is_verified' => (bool) ($user['is_verified'] ?? false),
                    'text' => $comment['text'] ?? null,
                    'like_count' => (int) ($comment['comment_like_count'] ?? 0),
                    'reply_count' => (int) ($comment['child_comment_count'] ?? 0),
                    'commented_at' => $this->parseTime($comment['comment_time'] ?? null),
                ],
            );

            $stored++;
        }

        return $stored;
    }

    /**
     * Pull the Instagram shortcode out of a permalink.
     * Handles /p/, /reel/, /reels/ and /tv/ URLs.
     */
    public function shortcodeFrom(?string $permalink): ?string
    {
        if (blank($permalink)) {
            return null;
        }

        if (preg_match('#instagram\.com/(?:p|reel|reels|tv)/([A-Za-z0-9_-]+)#', $permalink, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Call the viewer for one shortcode.
     *
     * @return array<int, array<string, mixed>>|null  Comments, or null on any failure.
     */
    private function fetch(string $shortcode): ?array
    {
        $base = rtrim((string) config('services.dolphinradar.base_url'), '/');

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json, text/plain, */*',
                'Tenantid' => (string) config('services.dolphinradar.tenant_id'),
                'Time-Zone' => (string) config('services.dolphinradar.timezone'),
                'Is-Free' => 'true',
                'Biz-Func-Name' => 'comment_viewer',
                'Origin' => $base,
                'Referer' => $base.'/instagram-viewer/post-comments',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                    .'(KHTML, like Gecko) Chrome/126.0 Safari/537.36',
            ])
                ->timeout(25)
                ->withBody('{}', 'application/json')
                ->post("{$base}/api/ins/post-comment/comments/{$shortcode}");
        } catch (Throwable $e) {
            Log::warning("Comment viewer error untuk {$shortcode}: ".$e->getMessage());

            return null;
        }

        if ($response->failed() || (int) $response->json('code', -1) !== 0) {
            Log::info("Comment viewer tidak mengembalikan data untuk {$shortcode}.");

            return null;
        }

        return $response->json('data.comments', []);
    }

    private function parseTime(?string $time): ?Carbon
    {
        if (blank($time)) {
            return null;
        }

        try {
            return Carbon::parse($time, (string) config('services.dolphinradar.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
