<?php

namespace App\Services\Analytics;

use App\Models\AccountMedia;
use App\Models\AccountMetric;
use App\Models\SocialAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns the daily snapshots into "how much did we grow, and by what percent"
 * over any window — today, 3 days, a week, a month, a year, or a custom range.
 *
 * Instagram reports insights as lifetime totals, so growth for a window is
 * (total at the end) − (total the day before it started). The same window
 * length immediately before is used as the comparison baseline.
 */
class MetricsComparison
{
    /** value => [label, days] */
    public const PERIODS = [
        'today' => ['Hari ini', 1],
        '3days' => ['3 hari', 3],
        'week' => ['7 hari', 7],
        'month' => ['30 hari', 30],
        'year' => ['1 tahun', 365],
    ];

    /**
     * @return array{
     *   period: array{label:string,from:string,to:string,previous_from:string,previous_to:string},
     *   metrics: array<string, array{label:string,current:int,previous:int,change:int,percent:?float}>
     * }
     */
    public function forAccount(
        SocialAccount $account,
        string $period = 'week',
        ?Carbon $customFrom = null,
        ?Carbon $customTo = null,
    ): array {
        [$from, $to, $label] = $this->resolveWindow($period, $customFrom, $customTo);

        $length = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($length - 1);

        return [
            'period' => [
                'label' => $label,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'previous_from' => $prevFrom->toDateString(),
                'previous_to' => $prevTo->toDateString(),
            ],
            'metrics' => [
                ...$this->followerMetrics($account, $from, $to, $prevFrom, $prevTo),
                ...$this->mediaMetrics($account, $from, $to, $prevFrom, $prevTo),
            ],
        ];
    }

    /**
     * Per-day growth series for charts.
     *
     * @return array{labels: array<int,string>, series: array<string, array<int,int>>}
     */
    public function dailySeries(SocialAccount $account, int $days = 14, array $metrics = ['likes', 'views', 'reach']): array
    {
        $labels = [];
        $series = array_fill_keys($metrics, []);

        // Totals for the day before the window, so day one has a baseline.
        $previous = $this->mediaTotalsAt($account, today()->subDays($days));

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = today()->subDays($i);
            $totals = $this->mediaTotalsAt($account, $day);

            $labels[] = $day->translatedFormat('d M');

            foreach ($metrics as $metric) {
                $series[$metric][] = max(0, ($totals[$metric] ?? 0) - ($previous[$metric] ?? 0));
            }

            $previous = $totals;
        }

        return ['labels' => $labels, 'series' => $series];
    }

    /**
     * Follower count on each day of the window — the actual value, not growth,
     * so a line chart shows the trajectory.
     *
     * @return array{labels: array<int,string>, followers: array<int,int>, gained: array<int,int>}
     */
    public function followerSeries(SocialAccount $account, int $days = 30): array
    {
        $labels = [];
        $followers = [];
        $gained = [];

        $previous = $this->followersAt($account, today()->subDays($days));

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = today()->subDays($i);
            $value = $this->followersAt($account, $day);

            $labels[] = $day->translatedFormat('d M');
            $followers[] = $value;
            $gained[] = $value - $previous;   // may be negative (unfollows)

            $previous = $value;
        }

        return ['labels' => $labels, 'followers' => $followers, 'gained' => $gained];
    }

    /**
     * How many days a period selection spans, for the daily trend chart.
     * Capped so a "1 tahun" view doesn't fire 365 day-by-day queries or
     * render an unreadable chart — the caller labels the chart with the
     * actual number of points returned.
     */
    public function periodDays(string $period, ?Carbon $from = null, ?Carbon $to = null): int
    {
        [$start, $end] = $this->resolveWindow($period, $from, $to);

        return min(92, max(2, $start->diffInDays($end) + 1));
    }

    /**
     * Every current headline number for an account: audience counts plus the
     * summed lifetime totals of all its posts, and a couple of derived figures.
     *
     * @return array<string, int|float|null>
     */
    public function currentTotals(SocialAccount $account): array
    {
        $media = $this->mediaTotalsAt($account, today());

        $followers = $this->followersAt($account, today());
        $follows = (int) AccountMetric::where('social_account_id', $account->id)
            ->orderByDesc('captured_on')
            ->value('follows');

        $trackedPosts = AccountMedia::where('social_account_id', $account->id)->count();
        $interactions = (int) ($media['interactions'] ?? 0);
        $avg = $trackedPosts > 0 ? (int) round($interactions / $trackedPosts) : 0;

        return [
            'followers' => $followers,
            'follows' => $follows,                 // "following"
            'media_count' => (int) $account->media_count,
            'tracked_posts' => $trackedPosts,
            'likes' => (int) ($media['likes'] ?? 0),
            'comments' => (int) ($media['comments'] ?? 0),
            'views' => (int) ($media['views'] ?? 0),
            'reach' => (int) ($media['reach'] ?? 0),
            'saves' => (int) ($media['saves'] ?? 0),
            'shares' => (int) ($media['shares'] ?? 0),
            'interactions' => $interactions,
            'avg_interactions' => $avg,
            // Simple engagement rate: average interactions per post vs audience.
            'engagement_rate' => $followers > 0 ? round($avg / $followers * 100, 2) : null,
        ];
    }

    /** Growth for every period at once — handy for a summary strip. */
    public function allPeriods(SocialAccount $account): array
    {
        return collect(self::PERIODS)
            ->keys()
            ->mapWithKeys(fn (string $key) => [$key => $this->forAccount($account, $key)])
            ->all();
    }

    /* -----------------------------------------------------------------
     | Followers (point-in-time value)
     * ----------------------------------------------------------------- */

    private function followerMetrics(SocialAccount $account, Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo): array
    {
        $atEnd = $this->followersAt($account, $to);
        $atStart = $this->followersAt($account, $from->copy()->subDay());
        $atPrevStart = $this->followersAt($account, $prevFrom->copy()->subDay());

        $current = max(0, $atEnd - $atStart);
        $previous = max(0, $atStart - $atPrevStart);

        return [
            'followers' => $this->row('Followers Baru', $current, $previous),
            'followers_total' => $this->row('Total Followers', $atEnd, $atStart),
        ];
    }

    private function followersAt(SocialAccount $account, Carbon $date): int
    {
        return (int) AccountMetric::where('social_account_id', $account->id)
            ->where('captured_on', '<=', $date->toDateString())
            ->orderByDesc('captured_on')
            ->value('followers');
    }

    /* -----------------------------------------------------------------
     | Post insights (lifetime totals → derive growth)
     * ----------------------------------------------------------------- */

    private function mediaMetrics(SocialAccount $account, Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo): array
    {
        $end = $this->mediaTotalsAt($account, $to);
        $start = $this->mediaTotalsAt($account, $from->copy()->subDay());
        $prevStart = $this->mediaTotalsAt($account, $prevFrom->copy()->subDay());

        $labels = [
            'likes' => 'Like', 'comments' => 'Comment', 'views' => 'Views',
            'reach' => 'Reach', 'saves' => 'Save', 'shares' => 'Share',
            'interactions' => 'Interaksi',
        ];

        $metrics = [];

        foreach ($labels as $key => $label) {
            $current = max(0, ($end[$key] ?? 0) - ($start[$key] ?? 0));
            $previous = max(0, ($start[$key] ?? 0) - ($prevStart[$key] ?? 0));

            $metrics[$key] = $this->row($label, $current, $previous);
        }

        return $metrics;
    }

    /**
     * Sum of every post's most recent snapshot on or before $date.
     *
     * @return array<string, int>
     */
    private function mediaTotalsAt(SocialAccount $account, Carbon $date): array
    {
        $latest = DB::table('media_metrics')
            ->selectRaw('account_media_id, MAX(captured_on) as captured_on')
            ->where('captured_on', '<=', $date->toDateString())
            ->groupBy('account_media_id');

        $row = DB::table('media_metrics as m')
            ->joinSub($latest, 'l', fn ($join) => $join
                ->on('l.account_media_id', '=', 'm.account_media_id')
                ->on('l.captured_on', '=', 'm.captured_on'))
            ->join('account_media as am', 'am.id', '=', 'm.account_media_id')
            ->where('am.social_account_id', $account->id)
            ->selectRaw(
                'COALESCE(SUM(m.likes),0) likes, COALESCE(SUM(m.comments),0) comments,'
                .'COALESCE(SUM(m.views),0) views, COALESCE(SUM(m.reach),0) reach,'
                .'COALESCE(SUM(m.saves),0) saves, COALESCE(SUM(m.shares),0) shares,'
                .'COALESCE(SUM(m.interactions),0) interactions'
            )
            ->first();

        return (array) ($row ?? []);
    }

    /* -----------------------------------------------------------------
     | Helpers
     * ----------------------------------------------------------------- */

    /** @return array{label:string,current:int,previous:int,change:int,percent:?float} */
    private function row(string $label, int $current, int $previous): array
    {
        return [
            'label' => $label,
            'current' => $current,
            'previous' => $previous,
            'change' => $current - $previous,
            // Null means "no baseline" — the UI shows "Baru" instead of ∞.
            'percent' => $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null,
        ];
    }

    /** @return array{0: Carbon, 1: Carbon, 2: string} */
    private function resolveWindow(string $period, ?Carbon $from, ?Carbon $to): array
    {
        if ($period === 'custom' && $from && $to) {
            return [$from->copy()->startOfDay(), $to->copy()->startOfDay(), 'Kustom'];
        }

        [$label, $days] = self::PERIODS[$period] ?? self::PERIODS['week'];

        return [today()->subDays($days - 1), today(), $label];
    }
}
