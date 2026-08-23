<?php

namespace App\Services\Datasets;

use App\Models\Dataset;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Computes premium analytics from the database using set-based aggregate
 * queries (never hydrates rows). Results are cached and the cache key embeds
 * a dataset signature, so any change to the dataset auto-invalidates it.
 */
class DatasetAnalyticsService
{
    public function for(Dataset $dataset): array
    {
        $signature = $dataset->updated_at?->timestamp.'-'.$dataset->total_rows;

        return Cache::remember(
            "dataset:{$dataset->id}:analytics:{$signature}",
            (int) config('datasets.analytics_ttl', 900),
            fn () => $this->compute($dataset)
        );
    }

    public function forget(Dataset $dataset): void
    {
        // Touch bumps updated_at → next signature differs; explicit clear too.
        Cache::forget("dataset:{$dataset->id}:analytics:".$dataset->updated_at?->timestamp.'-'.$dataset->total_rows);
    }

    private function compute(Dataset $dataset): array
    {
        $id = $dataset->id;

        $summary = $this->summary($id);
        $total = (int) $summary->total;

        return [
            'summary' => [
                'total' => $total,
                'valid' => (int) $summary->valid,
                'invalid' => (int) $summary->invalid,
                'qualified' => (int) $summary->qualified,
                'unqualified' => $total - (int) $summary->qualified,
                'valid_rate' => $this->rate($summary->valid, $total),
                'qualified_rate' => $this->rate($summary->qualified, $total),
            ],
            'followers' => [
                'sum' => (int) $summary->followers_sum,
                'avg' => round((float) $summary->followers_avg, 0),
                'min' => (int) $summary->followers_min,
                'max' => (int) $summary->followers_max,
                'median' => $this->median($id, $total),
            ],
            'buckets' => $this->buckets($id),
            'platform_distribution' => $this->platformDistribution($id),
            'platform_performance' => $this->platformPerformance($id),
            'top_followers' => $this->topFollowers($id),
            'leaderboard' => $this->leaderboard($id),
            'growth_curve' => $this->growthCurve($id, $total),
            'heatmap' => $this->heatmap($id),
            'insights' => $this->insights($id, $summary, $total),
        ];
    }

    private function summary(int $id): object
    {
        return DB::table('dataset_items')
            ->where('dataset_id', $id)
            ->selectRaw('
                COUNT(*) total,
                COALESCE(SUM(is_valid), 0) valid,
                COALESCE(SUM(is_valid = 0), 0) invalid,
                COALESCE(SUM(is_qualified), 0) qualified,
                COALESCE(SUM(followers), 0) followers_sum,
                COALESCE(AVG(followers), 0) followers_avg,
                COALESCE(MIN(followers), 0) followers_min,
                COALESCE(MAX(followers), 0) followers_max,
                COALESCE(AVG(following), 0) following_avg,
                COALESCE(AVG(posts), 0) posts_avg,
                COALESCE(SUM(following), 0) following_sum
            ')
            ->first();
    }

    private function median(int $id, int $total): int
    {
        if ($total === 0) {
            return 0;
        }

        $row = DB::table('dataset_items')
            ->where('dataset_id', $id)
            ->orderBy('followers')
            ->offset((int) floor(($total - 1) / 2))
            ->limit(1)
            ->value('followers');

        return (int) $row;
    }

    private function buckets(int $id): array
    {
        $defs = array_merge(
            [['label' => '< 1K', 'min' => 0, 'max' => 999]],
            config('datasets.follower_buckets')
        );

        $select = [];
        foreach ($defs as $i => $b) {
            $cond = $b['max'] === null
                ? "followers >= {$b['min']}"
                : "followers BETWEEN {$b['min']} AND {$b['max']}";
            $select[] = "SUM(CASE WHEN {$cond} THEN 1 ELSE 0 END) b{$i}";
        }

        $row = (array) DB::table('dataset_items')
            ->where('dataset_id', $id)
            ->selectRaw(implode(',', $select))
            ->first();

        return array_map(fn ($b, $i) => [
            'label' => $b['label'],
            'count' => (int) ($row["b{$i}"] ?? 0),
        ], $defs, array_keys($defs));
    }

    private function platformDistribution(int $id): array
    {
        return DB::table('dataset_items')
            ->where('dataset_id', $id)
            ->selectRaw('COALESCE(NULLIF(platform, ""), "unknown") platform, COUNT(*) c')
            ->groupBy('platform')
            ->orderByDesc('c')
            ->get()
            ->map(fn ($r) => ['label' => $r->platform, 'count' => (int) $r->c])
            ->all();
    }

    private function platformPerformance(int $id): array
    {
        return DB::table('dataset_items')
            ->where('dataset_id', $id)
            ->selectRaw('
                COALESCE(NULLIF(platform, ""), "unknown") platform,
                COUNT(*) c,
                ROUND(AVG(followers)) avg_followers,
                COALESCE(SUM(is_qualified), 0) qualified
            ')
            ->groupBy('platform')
            ->orderByDesc('c')
            ->limit(8)
            ->get()
            ->map(fn ($r) => [
                'platform' => $r->platform,
                'count' => (int) $r->c,
                'avg_followers' => (int) $r->avg_followers,
                'qualified' => (int) $r->qualified,
            ])
            ->all();
    }

    private function topFollowers(int $id, int $limit = 10): array
    {
        return DB::table('dataset_items')
            ->where('dataset_id', $id)
            ->orderByDesc('followers')
            ->limit($limit)
            ->get(['name', 'username', 'platform', 'followers', 'profile_url', 'is_qualified'])
            ->map(fn ($r) => [
                'name' => $r->name ?: $r->username ?: '—',
                'username' => $r->username,
                'platform' => $r->platform,
                'followers' => (int) $r->followers,
                'profile_url' => $r->profile_url,
                'is_qualified' => (bool) $r->is_qualified,
            ])
            ->all();
    }

    private function leaderboard(int $id): array
    {
        $rows = $this->topFollowers($id, 10);

        foreach ($rows as $i => &$r) {
            $r['rank'] = $i + 1;
        }

        return $rows;
    }

    /**
     * Smooth "growth-style" curve — followers averaged across 20 ordered
     * tiers (single windowed query, no row hydration).
     */
    private function growthCurve(int $id, int $total): array
    {
        if ($total === 0) {
            return [];
        }

        $tiles = $total < 20 ? $total : 20;

        $rows = DB::select("
            SELECT g, ROUND(AVG(followers)) v
            FROM (
                SELECT followers, NTILE(?) OVER (ORDER BY followers) g
                FROM dataset_items WHERE dataset_id = ?
            ) t
            GROUP BY g ORDER BY g
        ", [$tiles, $id]);

        return array_map(fn ($r) => (int) $r->v, $rows);
    }

    /**
     * Platform × follower-bucket matrix for the heatmap.
     */
    private function heatmap(int $id): array
    {
        $defs = array_merge(
            [['label' => '< 1K', 'min' => 0, 'max' => 999]],
            config('datasets.follower_buckets')
        );

        $case = 'CASE ';
        foreach ($defs as $i => $b) {
            $cond = $b['max'] === null
                ? "followers >= {$b['min']}"
                : "followers BETWEEN {$b['min']} AND {$b['max']}";
            $case .= "WHEN {$cond} THEN {$i} ";
        }
        $case .= 'END';

        $rows = DB::table('dataset_items')
            ->where('dataset_id', $id)
            ->selectRaw('COALESCE(NULLIF(platform, ""), "unknown") platform, '.$case.' b, COUNT(*) c')
            ->groupBy('platform', 'b')
            ->get();

        $platforms = $rows->pluck('platform')->unique()
            ->take(6)->values()->all();

        $matrix = [];
        foreach ($platforms as $p) {
            $series = array_fill(0, count($defs), 0);
            foreach ($rows->where('platform', $p) as $r) {
                if ($r->b !== null) {
                    $series[(int) $r->b] = (int) $r->c;
                }
            }
            $matrix[] = ['platform' => $p, 'data' => $series];
        }

        return [
            'labels' => array_column($defs, 'label'),
            'series' => $matrix,
        ];
    }

    private function insights(int $id, object $s, int $total): array
    {
        $over = DB::table('dataset_items')
            ->where('dataset_id', $id)
            ->selectRaw('
                SUM(followers >= 10000) over10k,
                SUM(followers >= 100000) over100k
            ')
            ->first();

        $ratio = $s->following_sum > 0
            ? round($s->followers_sum / $s->following_sum, 2)
            : 0;

        return [
            'avg_posts' => round((float) $s->posts_avg, 1),
            'avg_following' => round((float) $s->following_avg, 0),
            'follower_following_ratio' => $ratio,
            'accounts_over_10k' => (int) ($over->over10k ?? 0),
            'accounts_over_100k' => (int) ($over->over100k ?? 0),
            'reach' => (int) $s->followers_sum,
        ];
    }

    private function rate(int|float $part, int $total): float
    {
        return $total > 0 ? round($part / $total * 100, 1) : 0.0;
    }
}
