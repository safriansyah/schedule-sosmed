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

        // Content production numbers are for roles that work with content.
        // An Operator Follow Up or the front desk has no access to content,
        // so they neither see these nor pay for computing them.
        $seesContent = $user->can(Permission::ViewAnyContent->value);

        return view('dashboard.index', [
            'period' => $period,
            'periods' => DashboardService::PERIODS,
            'windowLabel' => $label,
            'from' => $from,
            'to' => $to,

            'seesContent' => $seesContent,
            'workflow' => $seesContent ? $this->dashboard->workflow($from, $to) : [],
            'trend' => $seesContent ? $this->dashboard->contentTrend($from, $to) : [],
            'breakdown' => $seesContent ? $this->dashboard->statusBreakdown() : collect(),
            'team' => $seesContent ? $this->dashboard->teamOutput($from, $to) : collect(),
            'accounts' => $seesContent ? $this->dashboard->accountPerformance($period) : collect(),
            'upcoming' => $seesContent ? $this->dashboard->upcoming() : collect(),

            // Today's queue, for whoever runs the Buku Tamu.
            'guestBook' => $user->can(Permission::ManageGuestBook->value) ? [
                'waiting' => \App\Models\GuestBookEntry::today()->where('status', \App\Enums\GuestBookStatus::Waiting->value)->count(),
                'serving' => \App\Models\GuestBookEntry::today()->whereIn('status', [\App\Enums\GuestBookStatus::Called->value, \App\Enums\GuestBookStatus::Serving->value])->count(),
                'done' => \App\Models\GuestBookEntry::today()->where('status', \App\Enums\GuestBookStatus::Done->value)->count(),
                'ticketed' => \App\Models\GuestBookEntry::today()->where('status', \App\Enums\GuestBookStatus::Ticketed->value)->count(),
            ] : null,

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
            // Everyone's activity is what the Aktivitas permission is for;
            // without it, a user sees their own actions only.
            'recentActivity' => Activity::with('user:id,name')
                ->when(! $user->can(Permission::ViewActivity->value), fn ($q) => $q->where('user_id', $user->id))
                ->latest()
                ->limit(8)
                ->get(),
            'lastSyncedAt' => SyncController::lastSyncedAt(),
        ]);
    }
}
