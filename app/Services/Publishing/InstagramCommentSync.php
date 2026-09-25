<?php

namespace App\Services\Publishing;

use App\Enums\InteractionType;
use App\Enums\SocialPlatform;
use App\Models\AccountMedia;
use App\Models\Interaction;
use App\Models\SocialAccount;
use App\Services\Crm\ContactResolver;
use App\Services\Media\RemoteImageCache;
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
 *
 * Comments land in `interactions` — the unified inbox shared with DMs and
 * other networks — and each one is resolved to a contact so the UI can show
 * who commented and whether they are one of our agents.
 */
class InstagramCommentSync
{
    public function __construct(
        private readonly ContactResolver $contacts,
        private readonly RemoteImageCache $images,
    ) {}

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

            $interaction = Interaction::updateOrCreate(
                ['channel' => SocialPlatform::Instagram->value, 'external_id' => (string) $pk],
                [
                    'type' => InteractionType::Comment->value,
                    'direction' => 'inbound',
                    'source_type' => AccountMedia::class,
                    'source_id' => $media->id,
                    'author_handle' => $user['username'] ?? null,
                    // pengirimpesanid. Handles get renamed; this id does not,
                    // so it is what actually identifies a commenter over time.
                    'author_external_id' => isset($user['pk']) ? (string) $user['pk'] : null,
                    'author_name' => filled($user['full_name'] ?? null) ? $user['full_name'] : null,
                    'author_avatar' => $user['profile_pic_url'] ?? null,
                    // Downloaded NOW, while the CDN signature is still valid —
                    // in a fortnight this URL returns nothing at all. Keyed on
                    // the handle so a changed avatar replaces the old file.
                    'author_avatar_path' => $this->images->store(
                        $user['profile_pic_url'] ?? null,
                        'avatars',
                        mb_strtolower((string) ($user['username'] ?? $pk)),
                    ),
                    'author_verified' => (bool) ($user['is_verified'] ?? false),
                    'text' => $comment['text'] ?? null,
                    'like_count' => (int) ($comment['comment_like_count'] ?? 0),
                    'reply_count' => (int) ($comment['child_comment_count'] ?? 0),
                    'occurred_at' => $this->parseTime($comment['comment_time'] ?? null),
                ],
            );

            // Best-effort: an unmatched commenter must not cost us the comment,
            // so a failure here is logged and the row stands with contact_id
            // null for `contacts:resolve` to pick up later.
            try {
                $this->contacts->resolveFor($interaction);
            } catch (Throwable $e) {
                Log::warning("Gagal mencocokkan kontak untuk komentar {$pk}: ".$e->getMessage());
            }

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
