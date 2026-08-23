<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\SocialAccount;
use App\Services\Analytics\MetricsComparison;
use App\Services\Analytics\PostingInsights;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Side-by-side period comparison for one account, plus the practical
 * conclusions drawn from it (best hour, best day, top posts).
 */
class AnalyticsController extends Controller
{
    public function __construct(
        private readonly MetricsComparison $comparison,
        private readonly PostingInsights $insights,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize(Permission::ViewAnalytics->value);

        $accounts = SocialAccount::active()->orderBy('name')->get();
        $account = $accounts->firstWhere('id', $request->input('account')) ?? $accounts->first();

        if (! $account) {
            return view('analytics.index', ['accounts' => $accounts, 'account' => null]);
        }

        // Every preset window at once — the point of this page is comparing them.
        $periods = collect(MetricsComparison::PERIODS)
            ->keys()
            ->mapWithKeys(fn (string $key) => [$key => $this->comparison->forAccount($account, $key)])
            ->all();

        return view('analytics.index', [
            'accounts' => $accounts,
            'account' => $account,
            'totals' => $this->comparison->currentTotals($account),
            'periods' => $periods,
            'labels' => MetricsComparison::PERIODS,
            'trend' => $this->comparison->dailySeries($account, 30, ['likes', 'comments', 'views', 'reach']),
            'followerSeries' => $this->comparison->followerSeries($account, 30),
            'byHour' => $this->insights->byHour($account),
            'byDay' => $this->insights->byDay($account),
            'bestHour' => $this->insights->bestHour($account),
            'bestDay' => $this->insights->bestDay($account),
            'topPosts' => $this->insights->topPosts($account),
            'recentComments' => $this->insights->recentComments($account),
        ]);
    }
}
