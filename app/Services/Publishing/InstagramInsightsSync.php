<?php

namespace App\Services\Publishing;

use App\Enums\InteractionType;
use App\Enums\SocialPlatform;
use App\Models\AccountMedia;
use App\Models\Interaction;
use App\Models\MediaMetric;
use App\Models\Schedule;
use App\Models\SocialAccount;
use App\Services\Crm\ContactResolver;
use App\Services\Media\RemoteImageCache;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Pulls each account's posts and their insight totals, then writes one
 * snapshot row per post per day.
 *
 * Instagram reports insights as *lifetime* totals, so the daily snapshots are
 * what let MetricsComparison derive growth over any window.
 */
class InstagramInsightsSync
{
    private const API = 'https://graph.instagram.com/v21.0';

    /** Insight metrics requested per post. */
    private const MEDIA_METRICS = 'reach,saved,shares,likes,comments,views,total_interactions';

    /** Posts fetched per API page. */
    private const PAGE_SIZE = 50;

    /** Hard ceiling on pages walked per run, so one account cannot hog the run. */
    private const MAX_PAGES = 40;   // 40 × 50 = 2000 posts

    public function __construct(
        private readonly ContactResolver $contacts,
        private readonly RemoteImageCache $images,
    ) {}

    /**
     * Insights cost one API call per post, and Instagram rate-limits hard.
     * Posts older than this are refreshed less often — their numbers have
     * usually settled, while recent posts are still moving.
     */
    private const HOT_DAYS = 30;

    /** How often a settled (older) post is refreshed. */
    private const COLD_REFRESH_DAYS = 7;

    /** Comments pulled per post per run. */
    private const COMMENT_LIMIT = 25;

    /**
     * @param  int|null  $maxPages  Page ceiling for this run; null uses MAX_PAGES.
     * @param  bool  $backfill  Resume deeper into history instead of starting
     *                          again at the newest post.
     * @return array{media: int, snapshots: int, failed: int, done: bool}
     */
    public function syncAll(?int $maxPages = null, bool $backfill = false): array
    {
        $totals = ['media' => 0, 'snapshots' => 0, 'failed' => 0, 'done' => true];

        foreach (SocialAccount::active()->get() as $account) {
            if ($account->platform !== SocialPlatform::Instagram) {
                continue;
            }

            try {
                $result = $this->sync($account, $maxPages, $backfill);

                // "done" only when every account has reached the end of its
                // history, so one unfinished account keeps the report honest.
                $totals['done'] = $totals['done'] && $result['done'];
                $totals['media'] += $result['media'];
                $totals['snapshots'] += $result['snapshots'];
            } catch (Throwable $e) {
                $totals['failed']++;
                Log::warning("Gagal sinkron insight {$account->name}: ".$e->getMessage());
            }
        }

        return $totals;
    }

    /**
     * Two modes.
     *
     * REFRESH (default) walks from the newest post, stopping at the age window.
     * This is the routine run: cheap, and it keeps recent numbers current.
     *
     * BACKFILL resumes from the account's stored cursor and ignores the window,
     * so each run reaches further back than the last. Instagram's media list is
     * paged, and the per-run page ceiling meant the walk could never get past
     * the first MAX_PAGES x PAGE_SIZE posts however often it ran - an account
     * with 2.227 posts was permanently capped at 2.000. Remembering the cursor
     * is what turns "run it again" into progress rather than repetition.
     *
     * @return array{media: int, snapshots: int, done: bool}
     */
    public function sync(SocialAccount $account, ?int $maxPages = null, bool $backfill = false): array
    {
        if (blank($account->access_token)) {
            throw new RuntimeException('Access token belum diisi.');
        }

        $client = Http::withToken($account->access_token)->timeout(30)->baseUrl(self::API);

        $seen = 0;
        $snapshots = 0;

        $walk = $this->walkMedia(
            $client,
            cutoff: $backfill ? null : $this->cutoff(),
            startUrl: $backfill ? $account->media_cursor : null,
            maxPages: $maxPages ?? self::MAX_PAGES,
        );

        foreach ($walk as $post) {
            $seen++;
            $media = $this->storeMedia($account, $post);

            // Skip the expensive insights call for posts whose numbers have
            // already settled and were refreshed recently.
            if (! $this->needsRefresh($media)) {
                continue;
            }

            if ($this->storeInsights($client, $media)) {
                $snapshots++;
            }

            $this->storeComments($client, $media);
        }

        // Where the walk stopped. null means it ran out of pages - the end of
        // the account's history. Only meaningful once the generator has
        // finished, which the foreach above guarantees.
        $next = $walk->getReturn();

        if ($backfill) {
            $account->forceFill([
                'media_cursor' => $next,
                // Stamped only on reaching the end, so a later run can tell
                // "finished" from "not started" - a null cursor means both.
                'media_backfilled_at' => $next === null ? now() : $account->media_backfilled_at,
            ])->saveQuietly();
        }

        return ['media' => $seen, 'snapshots' => $snapshots, 'done' => $next === null];
    }

    /**
     * The oldest post worth syncing, or null when every post is wanted.
     *
     * Set with INSTAGRAM_MAX_AGE_DAYS; 0 removes the limit.
     */
    private function cutoff(): ?Carbon
    {
        $days = (int) config('services.instagram.max_age_days', 90);

        return $days > 0 ? now()->subDays($days)->startOfDay() : null;
    }

    /**
     * Walk every page of the media list, yielding one post at a time so a
     * 1000-post account never sits in memory at once.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function walkMedia(
        PendingRequest $client,
        ?Carbon $cutoff = null,
        ?string $startUrl = null,
        ?int $maxPages = null,
    ): Generator {
        // A stored cursor is an absolute URL that already carries fields and
        // limit, so the query parameters are only sent on a fresh walk.
        $fresh = $startUrl === null;
        $url = $startUrl ?? '/me/media';
        $maxPages = max(1, $maxPages ?? self::MAX_PAGES);

        $params = [
            'fields' => 'id,caption,media_type,media_product_type,permalink,thumbnail_url,media_url,timestamp,like_count,comments_count',
            'limit' => self::PAGE_SIZE,
        ];

        for ($page = 0; $page < $maxPages && $url !== null; $page++) {
            // Every page carries the token, including the ones reached through
            // `paging.next`.
            //
            // That URL looks signed and IS on the Facebook Graph API, which
            // embeds access_token in its paging links. graph.instagram.com does
            // not: it returns
            //   https://graph.instagram.com/v26.0/<id>/media?fields=…&after=…
            // with no credential at all, and relies on the Authorization
            // header. Sending it bare produced "Invalid OAuth 2.0 Access Token"
            // (code 190) on page two of every account with more than 50 posts —
            // page one stored fine, so it read as a token problem rather than a
            // paging one.
            //
            // An absolute URL bypasses the client's baseUrl, so passing it to
            // the same $client keeps the header and the full link.
            $response = $page === 0 && $fresh
                ? $client->get($url, $params)
                : $client->get($url);

            if ($response->failed()) {
                throw new RuntimeException('Gagal mengambil daftar media: '.str($response->body())->limit(160));
            }

            foreach ($response->json('data', []) as $post) {
                // Instagram returns the media list newest-first, so the first
                // post outside the window means every remaining post is too —
                // there is nothing to gain from asking for the next page.
                //
                // A post with no timestamp is never used to stop the walk: it
                // would end the run early on a data quirk rather than on age.
                if ($cutoff !== null
                    && isset($post['timestamp'])
                    && Carbon::parse($post['timestamp'])->lt($cutoff)) {
                    return;
                }

                yield $post;
            }

            $url = $response->json('paging.next');
        }

        // Handed back through Generator::getReturn(): where to resume, or null
        // when the account has no further pages.
        return $url;
    }

    /**
     * Recent posts are refreshed every run; settled ones only weekly. Insights
     * cost one API call per post, so this keeps large accounts within quota.
     */
    private function needsRefresh(AccountMedia $media): bool
    {
        $isHot = $media->posted_at === null
            || $media->posted_at->gt(now()->subDays(self::HOT_DAYS));

        if ($isHot) {
            return true;
        }

        $last = $media->metrics()->max('captured_at');

        return $last === null || Carbon::parse($last)->lt(now()->subDays(self::COLD_REFRESH_DAYS));
    }

    /** @param array<string, mixed> $post */
    private function storeMedia(SocialAccount $account, array $post): AccountMedia
    {
        // Attribute the post to our own content when we published it.
        $contentId = Schedule::where('social_account_id', $account->id)
            ->where('external_id', $post['id'])
            ->value('content_id');

        return AccountMedia::updateOrCreate(
            ['social_account_id' => $account->id, 'external_id' => $post['id']],
            [
                'content_id' => $contentId,
                'caption' => $post['caption'] ?? null,
                'media_type' => $post['media_type'] ?? null,
                'product_type' => $post['media_product_type'] ?? null,
                'permalink' => $post['permalink'] ?? null,
                'thumbnail_url' => $thumbnail = $post['thumbnail_url'] ?? $post['media_url'] ?? null,
                // Downloaded now, while the CDN signature is still valid. These
                // links carry an `oe=` expiry and stop resolving after about a
                // fortnight, which is why the stored URL alone is not enough.
                'thumbnail_path' => $this->images->store($thumbnail, 'thumbnails', $post['id']),
                'posted_at' => isset($post['timestamp']) ? Carbon::parse($post['timestamp']) : null,
            ],
        );
    }

    /** Snapshot today's lifetime totals for one post. */
    private function storeInsights(PendingRequest $client, AccountMedia $media): bool
    {
        $response = $client->get("/{$media->external_id}/insights", ['metric' => self::MEDIA_METRICS]);

        if ($response->failed()) {
            // Stories expire and some types report no insights — not fatal.
            Log::info("Insight tidak tersedia untuk media {$media->external_id}.");

            return false;
        }

        $values = $this->flatten($response->json('data', []));

        MediaMetric::updateOrCreate(
            ['account_media_id' => $media->id, 'captured_at' => now()->startOfHour()],
            [
                'captured_on' => today(),
                'likes' => $values['likes'] ?? 0,
                'comments' => $values['comments'] ?? 0,
                'views' => $values['views'] ?? 0,
                'reach' => $values['reach'] ?? 0,
                'saves' => $values['saved'] ?? 0,
                'shares' => $values['shares'] ?? 0,
                'interactions' => $values['total_interactions'] ?? 0,
            ],
        );

        return true;
    }

    /**
     * Pull the latest comments on a post.
     *
     * Requires the `instagram_business_manage_comments` scope; without it the
     * endpoint answers 200 with an empty list, so a quiet skip is correct.
     */
    private function storeComments(PendingRequest $client, AccountMedia $media): void
    {
        $response = $client->get("/{$media->external_id}/comments", [
            'fields' => 'id,text,username,timestamp,like_count',
            'limit' => self::COMMENT_LIMIT,
        ]);

        if ($response->failed()) {
            return;
        }

        foreach ($response->json('data', []) as $comment) {
            // Same unified inbox the viewer-based sync writes to, keyed on the
            // same (channel, external_id) — so whichever source sees a comment
            // first, the other updates the row rather than duplicating it.
            $interaction = Interaction::updateOrCreate(
                ['channel' => SocialPlatform::Instagram->value, 'external_id' => $comment['id']],
                [
                    'type' => InteractionType::Comment->value,
                    'direction' => 'inbound',
                    'source_type' => AccountMedia::class,
                    'source_id' => $media->id,
                    'author_handle' => $comment['username'] ?? null,
                    'text' => $comment['text'] ?? null,
                    'like_count' => $comment['like_count'] ?? 0,
                    'occurred_at' => isset($comment['timestamp']) ? Carbon::parse($comment['timestamp']) : null,
                ],
            );

            try {
                $this->contacts->resolveFor($interaction);
            } catch (Throwable $e) {
                Log::warning('Gagal mencocokkan kontak komentar Graph API: '.$e->getMessage());
            }
        }
    }

    /**
     * Insights come back as [{name, values:[{value}]}] — flatten to name => value.
     *
     * @param  array<int, array<string, mixed>>  $data
     * @return array<string, int>
     */
    private function flatten(array $data): array
    {
        $out = [];

        foreach ($data as $metric) {
            $out[$metric['name']] = (int) ($metric['values'][0]['value'] ?? 0);
        }

        return $out;
    }
}
