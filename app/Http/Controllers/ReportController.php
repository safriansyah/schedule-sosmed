<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\StudentCondition;
use App\Enums\TaskStatus;
use App\Enums\TicketFlag;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Models\FollowUp;
use App\Models\Interaction;
use App\Models\Student;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\Ticket;
use App\Services\ActivityLogger;
use App\Services\Export\Exporter;
use App\Services\Students\StudentStats;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Reporting and export — one report per module, not one page for everything.
 *
 * The combined page was asked to be split up, and it deserved to be: a single
 * screen mixing student counts, ticket counts and task counts is read by
 * nobody, because no one person owns all three. Each report now lives beside
 * the module it describes, and `index()` is only a short hub that points at
 * them so existing links and the old menu entry still land somewhere useful.
 *
 * All of them read their student numbers from StudentStats so the reports, the
 * dashboard and the student list can never disagree — three screens quoting
 * three different totals is how people stop trusting all of them.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly StudentStats $stats,
        private readonly ActivityLogger $log,
    ) {}

    /** A hub, not a report: three cards pointing at the real ones. */
    public function index(Request $request): View
    {
        $this->authorize(Permission::ViewReports->value);

        $user = $request->user();

        return view('reports.index', [
            'students' => $this->stats->summary($user),
            'tickets' => $this->ticketStats($user),
            'tasks' => $this->taskStats(),
            'canExport' => $user->hasPermission(Permission::ExportData),
        ]);
    }

    /**
     * Laporan Ticketing.
     *
     * Grouped by the coarse Open / Pending / Closed stages, because that is
     * the question a report answers. Tickets raised from the student import
     * additionally get the region breakdown — they are the only ones that have
     * a region at all.
     */
    public function tickets(Request $request): View
    {
        $this->authorize(Permission::ViewReports->value);

        $user = $request->user();

        $filters = $request->only('source', 'stage', 'from', 'to', ...StudentStats::REGION_LEVELS);
        $fromImport = ($filters['source'] ?? null) === TicketSource::StudentImport->value;

        // A closure, not a shared builder: each breakdown below adds its own
        // join and group, and reusing one instance would let them contaminate
        // each other in ways that are invisible until a total is wrong.
        $base = fn () => Ticket::query()
            ->visibleTo($user)
            ->when(filled($filters['source'] ?? null), fn ($q) => $q->where('source', $filters['source']))
            ->when(filled($filters['stage'] ?? null), fn ($q) => $q->whereIn('status', TicketStatus::inStage($filters['stage'])))
            ->when(filled($filters['from'] ?? null), fn ($q) => $q->whereDate('tickets.created_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($q) => $q->whereDate('tickets.created_at', '<=', $filters['to']))
            ->regionOf($filters);

        $byStatus = $base()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        $byStage = ['open' => 0, 'pending' => 0, 'closed' => 0];

        foreach (TicketStatus::cases() as $status) {
            $byStage[$status->stage()] += (int) ($byStatus[$status->value] ?? 0);
        }

        return view('reports.tickets', [
            'filters' => $filters,
            'total' => (int) $byStatus->sum(),
            'byStage' => $byStage,
            'byStatus' => $byStatus->map(fn ($v) => (int) $v)->all(),
            'bySource' => $base()->selectRaw('source, COUNT(*) as total')->groupBy('source')->pluck('total', 'source'),
            'byCategory' => $base()
                ->leftJoin('ticket_categories', 'ticket_categories.id', '=', 'tickets.category_id')
                ->groupBy('ticket_categories.name')
                ->selectRaw("COALESCE(ticket_categories.name, 'Tanpa kategori') as name, COUNT(*) as total")
                ->orderByDesc('total')
                ->limit(20)
                ->get(),
            'byOperator' => $base()
                ->leftJoin('users', 'users.id', '=', 'tickets.assigned_to')
                ->groupBy('users.name')
                ->selectRaw("COALESCE(users.name, 'Belum ditugaskan') as name, COUNT(*) as total")
                ->orderByDesc('total')
                ->limit(15)
                ->get(),
            // Only meaningful for import tickets, so only computed for them.
            'byRegion' => $fromImport ? $this->ticketsByRegion($base()) : collect(),
            'fromImport' => $fromImport,
            'regions' => $fromImport ? $this->stats->regionTree($user, $filters) : [],
            'regionLevels' => StudentStats::REGION_LEVELS,
            'regionLabels' => StudentStats::regionLabels(),
            'sources' => TicketSource::options(),
            'stages' => TicketStatus::stages(),
            'statuses' => TicketStatus::options(),
            'flags' => $this->ticketStats($user),
            'followUps' => $this->followUpStats(),
            'social' => $this->socialStats(),
            'canExport' => $user->hasPermission(Permission::ExportData),
        ]);
    }

    /** Laporan Data Mahasiswa. */
    public function students(Request $request): View
    {
        $this->authorize(Permission::ViewReports->value);

        $user = $request->user();

        return view('reports.students', [
            'students' => $this->stats->summary($user),
            'workload' => $this->stats->workload(),
            'byCondition' => $this->stats->byCondition($user),
            'byRegion' => $this->stats->byRegion($user, 25),
            'conditions' => StudentCondition::options(),
            // How much of the list has become a ticket — the bridge between
            // this report and the ticket one.
            'withTicket' => Student::whereHas('tickets')->count(),
            'total' => Student::count(),
            'canExport' => $user->hasPermission(Permission::ExportData),
        ]);
    }

    /** Laporan Task Management. */
    public function tasks(Request $request): View
    {
        $this->authorize(Permission::ViewReports->value);

        $from = $request->filled('from') ? Carbon::parse($request->input('from')) : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->input('to')) : now()->endOfMonth();

        if ($to->lt($from)) {
            $to = $from->copy()->endOfMonth();
        }

        return view('reports.tasks', [
            'from' => $from,
            'to' => $to,
            'stats' => $this->taskStats(),
            'byStatus' => Task::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'byPic' => Task::query()
                ->leftJoin('users', 'users.id', '=', 'tasks.pic_id')
                ->groupBy('users.name')
                ->selectRaw("COALESCE(users.name, 'Tanpa PIC') as name, COUNT(*) as total")
                ->selectRaw('SUM(tasks.status = ?) as done', [TaskStatus::Completed->value])
                ->orderByDesc('total')
                ->get(),
            // The planner ticks are the only record of what actually happened
            // on a given day, so the report counts them rather than re-reading
            // the schedule and calling a plan an outcome.
            'activity' => TaskCheck::query()
                ->whereBetween('task_checks.date', [$from->toDateString(), $to->toDateString()])
                ->join('tasks', 'tasks.id', '=', 'task_checks.task_id')
                ->groupBy('tasks.id', 'tasks.title')
                ->selectRaw('tasks.title as title, COUNT(*) as days')
                ->orderByDesc('days')
                ->limit(20)
                ->get(),
            'statuses' => TaskStatus::options(),
            'canExport' => $request->user()->hasPermission(Permission::ExportData),
        ]);
    }

    /**
     * Export a follow-up history — the one export with no screen of its own,
     * because the history is read inside each ticket.
     */
    public function followUps(Request $request, Exporter $exporter)
    {
        $this->authorize(Permission::ExportData->value);

        $query = FollowUp::query()
            ->where('followupable_type', (new Ticket)->getMorphClass())
            ->with(['user:id,name'])
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')));

        // Ticket numbers in one lookup rather than a join, so the export keeps
        // using the same keyset walk as every other export.
        $numbers = Ticket::pluck('number', 'id');

        $this->log->log('report.exported', 'Export riwayat follow up', null, [
            'format' => $request->input('format', 'xlsx'),
        ]);

        return $exporter->download(
            format: (string) $request->input('format', 'xlsx'),
            filename: 'follow-up',
            columns: [
                'ticket' => 'Tiket',
                'created_at' => 'Tanggal',
                'user' => 'Operator',
                'role' => 'Role',
                'channel' => 'Metode',
                'action' => 'Tindakan',
                'outcome' => 'Hasil',
                'response' => 'Catatan',
                'next' => 'Tindak Lanjut Berikutnya',
                'additional' => 'Data Tambahan',
            ],
            query: $query,
            mapper: fn (FollowUp $f) => [
                $numbers[$f->followupable_id] ?? $f->followupable_id,
                $f->created_at,
                $f->user?->name,
                $f->role_at_time,
                $f->channel_used,
                $f->action,
                $f->outcome,
                $f->response_text,
                $f->next_action_at,
                $f->additional_data,
            ],
        );
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    /**
     * Tickets per region, at the deepest level the data actually carries.
     *
     * Grouped on kabupaten rather than provinsi: the institution's export has
     * no province column, so grouping on it would produce a single row
     * labelled "Tanpa wilayah" holding everything — which reads as a broken
     * report rather than as missing data.
     */
    private function ticketsByRegion($query)
    {
        return $query
            ->join('students', 'students.id', '=', 'tickets.student_id')
            ->groupBy('students.kabupaten')
            ->selectRaw("COALESCE(students.kabupaten, 'Tanpa wilayah') as name, COUNT(*) as total")
            ->orderByDesc('total')
            ->limit(25)
            ->get();
    }

    /** @return array<string, int> */
    private function taskStats(): array
    {
        $byStatus = Task::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'total' => (int) $byStatus->sum(),
            'planned' => (int) ($byStatus[TaskStatus::Planned->value] ?? 0),
            'in_progress' => (int) ($byStatus[TaskStatus::InProgress->value] ?? 0),
            'completed' => (int) ($byStatus[TaskStatus::Completed->value] ?? 0),
            'overdue' => Task::whereIn('status', [TaskStatus::Planned->value, TaskStatus::InProgress->value])
                ->whereDate('due_date', '<', now()->toDateString())
                ->count(),
            'public' => Task::where('is_public', true)->count(),
            'checked_days' => TaskCheck::count(),
        ];
    }

    /** @return array<string, int> */
    private function ticketStats($user): array
    {
        $counts = Ticket::query()
            ->visibleTo($user)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $stats = ['total' => (int) $counts->sum()];

        foreach (TicketStatus::cases() as $status) {
            $stats[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        // Lead is orthogonal to status — a closed ticket can still have been a
        // lead, and that is exactly what the acquisition side wants counted.
        $byFlag = Ticket::query()
            ->visibleTo($user)
            ->selectRaw('flag, COUNT(*) as total')
            ->groupBy('flag')
            ->pluck('total', 'flag');

        $stats['lead'] = (int) ($byFlag[TicketFlag::Lead->value] ?? 0);
        $stats['netral'] = (int) ($byFlag[TicketFlag::Netral->value] ?? 0);

        return $stats;
    }

    /**
     * How much of the social inbox has become a ticket — the brief's three
     * social numbers.
     *
     * One grouped query over tickets plus one count of interactions, rather
     * than a correlated subquery per row.
     *
     * @return array<string, int>
     */
    private function socialStats(): array
    {
        $comments = Interaction::where('direction', 'inbound')->count();

        $withTicket = Ticket::whereNotNull('interaction_id')
            ->distinct()
            ->count('interaction_id');

        return [
            'comments' => $comments,
            'with_ticket' => $withTicket,
            'without_ticket' => max(0, $comments - $withTicket),
            'from_social' => Ticket::whereIn('source', ['instagram', 'tiktok', 'youtube', 'whatsapp'])->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function followUpStats(): array
    {
        $ticketType = (new Ticket)->getMorphClass();

        return [
            'total' => FollowUp::where('followupable_type', $ticketType)->count(),
            'this_month' => FollowUp::where('followupable_type', $ticketType)
                ->whereDate('created_at', '>=', now()->startOfMonth())
                ->count(),
            'due' => FollowUp::where('followupable_type', $ticketType)->due()->count(),
            'byOperator' => FollowUp::query()
                ->where('followupable_type', $ticketType)
                ->join('users', 'users.id', '=', 'follow_ups.user_id')
                ->groupBy('follow_ups.user_id', 'users.name')
                ->selectRaw('users.name as name, COUNT(*) as total')
                ->orderByDesc('total')
                ->limit(10)
                ->get(),
        ];
    }
}
