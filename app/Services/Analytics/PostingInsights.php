<?php

namespace App\Services\Analytics;

use App\Models\MediaComment;
use App\Models\SocialAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Derives practical advice from the snapshots we already collect: which hours
 * and days actually perform, and which posts lead the pack.
 */
class PostingInsights
{
    /** Indonesian day names, indexed the way MySQL DAYOFWEEK() returns them. */
    private const DAYS = [1 => 'Minggu', 2 => 'Senin', 3 => 'Selasa', 4 => 'Rabu', 5 => 'Kamis', 6 => 'Jumat', 7 => 'Sabtu'];

    /**
     * Average interactions grouped by the hour a post went out.
     *
     * @return Collection<int, array{hour:int,label:string,posts:int,avg:float}>
     */
    public function byHour(SocialAccount $account): Collection
    {
        return $this->grouped($account, 'HOUR(am.posted_at)')
            ->map(fn ($row) => [
                'hour' => (int) $row->bucket,
                'label' => str_pad((string) $row->bucket, 2, '0', STR_PAD_LEFT).':00',
                'posts' => (int) $row->posts,
                'avg' => round((float) $row->avg_interactions, 1),
            ])
            ->sortBy('hour')
            ->values();
    }

    /**
     * Average interactions grouped by weekday.
     *
     * @return Collection<int, array{day:int,label:string,posts:int,avg:float}>
     */
    public function byDay(SocialAccount $account): Collection
    {
        return $this->grouped($account, 'DAYOFWEEK(am.posted_at)')
            ->map(fn ($row) => [
                'day' => (int) $row->bucket,
                'label' => self::DAYS[(int) $row->bucket] ?? '-',
                'posts' => (int) $row->posts,
                'avg' => round((float) $row->avg_interactions, 1),
            ])
            ->sortBy('day')
            ->values();
    }

    /** The single best hour/day, or null when there is not enough data yet. */
    public function bestHour(SocialAccount $account): ?array
    {
        return $this->byHour($account)->sortByDesc('avg')->first();
    }

    public function bestDay(SocialAccount $account): ?array
    {
        return $this->byDay($account)->sortByDesc('avg')->first();
    }

    /** Top posts by interactions in the latest snapshot. */
    public function topPosts(SocialAccount $account, int $limit = 5): Collection
    {
        return DB::table('account_media as am')
            ->joinSub($this->latestSnapshot(), 'l', fn ($j) => $j
                ->on('l.account_media_id', '=', 'am.id'))
            ->join('media_metrics as m', fn ($j) => $j
                ->on('m.account_media_id', '=', 'am.id')
                ->on('m.captured_at', '=', 'l.captured_at'))
            ->where('am.social_account_id', $account->id)
            ->orderByDesc('m.interactions')
            ->limit($limit)
            ->select([
                'am.id', 'am.caption', 'am.permalink', 'am.thumbnail_url',
                'am.product_type', 'am.posted_at',
                'm.likes', 'm.comments', 'm.views', 'm.reach', 'm.interactions',
            ])
            ->get();
    }

    /** Newest comments across every monitored post on the account. */
    public function recentComments(SocialAccount $account, int $limit = 10): Collection
    {
        return MediaComment::query()
            ->with('media:id,caption,thumbnail_url,permalink')
            ->whereHas('media', fn ($q) => $q->where('social_account_id', $account->id))
            ->orderByDesc('commented_at')
            ->limit($limit)
            ->get();
    }

    /* ----------------------------------------------------------------- */

    private function grouped(SocialAccount $account, string $expression): Collection
    {
        return DB::table('account_media as am')
            ->joinSub($this->latestSnapshot(), 'l', fn ($j) => $j
                ->on('l.account_media_id', '=', 'am.id'))
            ->join('media_metrics as m', fn ($j) => $j
                ->on('m.account_media_id', '=', 'am.id')
                ->on('m.captured_at', '=', 'l.captured_at'))
            ->where('am.social_account_id', $account->id)
            ->whereNotNull('am.posted_at')
            ->selectRaw("{$expression} as bucket, COUNT(*) as posts, AVG(m.interactions) as avg_interactions")
            ->groupBy(DB::raw($expression))
            ->get();
    }

    private function latestSnapshot()
    {
        return DB::table('media_metrics')
            ->selectRaw('account_media_id, MAX(captured_at) as captured_at')
            ->groupBy('account_media_id');
    }
}
