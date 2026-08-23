<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Repositories\ContentRepository;
use App\Services\DashboardService;
use App\Http\Controllers\SyncController;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly ContentRepository $contents,
    ) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();

        $period = array_key_exists($request->input('period'), DashboardService::PERIODS)
            ? $request->input('period')
            : '30days';

        [$from, $to, $label] = $this->dashboard->window($period);

        return view('dashboard.index', [
            'period' => $period,
            'periods' => DashboardService::PERIODS,
            'windowLabel' => $label,
            'from' => $from,
            'to' => $to,

            'workflow' => $this->dashboard->workflow($from, $to),
            'trend' => $this->dashboard->contentTrend($from, $to),
            'breakdown' => $this->dashboard->statusBreakdown(),
            'team' => $this->dashboard->teamOutput($from, $to),
            'accounts' => $this->dashboard->accountPerformance($period),
            'upcoming' => $this->dashboard->upcoming(),

            'myWork' => $user->isCreative() ? $this->contents->needsAttentionFor($user) : collect(),
            'recentActivity' => Activity::with('user:id,name')->latest()->limit(8)->get(),
            'lastSyncedAt' => SyncController::lastSyncedAt(),
        ]);
    }
}
