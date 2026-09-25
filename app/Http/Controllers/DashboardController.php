<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Repositories\ContentRepository;
use App\Enums\Permission;
use App\Services\Crm\InboxSummary;
use App\Services\DashboardService;
use App\Services\Students\StudentStats;
use App\Services\SystemHealth;
use App\Http\Controllers\SyncController;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly ContentRepository $contents,
        private readonly InboxSummary $inbox,
        private readonly SystemHealth $health,
        private readonly StudentStats $students,
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

            // Only shown to roles that can act on it — a creative cannot fix
            // a stopped cron, and an alarm nobody can answer is just noise.
            'health' => $user->can(Permission::ManageSettings->value)
                || $user->can(Permission::ViewAccounts->value)
                ? $this->health->report()
                : null,

            // The CRM block is skipped entirely for roles that cannot see the
            // inbox — a creative should not pay for queries they never read.
            'crm' => $user->can(Permission::ViewInteractions->value) ? [
                'stats' => $this->inbox->headline(),
                'trend' => $this->inbox->sentimentTrend(14),
                'urgent' => $this->inbox->urgentQueue(5),
                'sla' => $this->inbox->slaPerformance(),
                'workload' => $this->inbox->workload(),
            ] : null,

            // Penanganan mahasiswa & tiket. Skipped for roles that cannot see
            // either, so a creative never pays for these queries. The numbers
            // come from StudentStats so they match the student list exactly.
            'handling' => $user->can(Permission::ViewStudents->value) || $user->can(Permission::ViewTickets->value)
                ? [
                    'students' => $user->can(Permission::ViewStudents->value)
                        ? $this->students->summary($user)
                        : null,
                    'workload' => $user->can(Permission::ViewAllStudents->value)
                        ? $this->students->workload()
                        : collect(),
                    'tickets' => $user->can(Permission::ViewTickets->value)
                        ? $this->dashboard->ticketSummary($user)
                        : null,
                    'social' => $user->can(Permission::ViewTickets->value)
                        ? $this->dashboard->socialTicketSummary()
                        : null,
                ]
                : null,

            'myWork' => $user->isCreative() ? $this->contents->needsAttentionFor($user) : collect(),
            'recentActivity' => Activity::with('user:id,name')->latest()->limit(8)->get(),
            'lastSyncedAt' => SyncController::lastSyncedAt(),
        ]);
    }
}
