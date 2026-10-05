<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Tasks\TaskPlanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Task Management — a module of its own, not part of Ticketing.
 *
 * A task is planned work with a start and a due date; a ticket is one person's
 * problem. They are never merged, though a task may reference the ticket it
 * grew out of.
 */
class TaskController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $log,
        private readonly TaskPlanner $planner,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize(Permission::ViewTasks->value);

        $filters = $request->only('q', 'status', 'priority', 'pic', 'visibility');

        // The planner window. Defaults to a fortnight from the start of this
        // week, which is what fits on a laptop without horizontal scrolling.
        $from = $this->date($request->input('from')) ?? now()->startOfWeek();
        $to = $this->date($request->input('to')) ?? (clone $from)->addDays(13);

        if ($to->lt($from)) {
            $to = (clone $from)->addDays(13);
        }

        // A very wide window would render thousands of day columns; cap it.
        if ($from->diffInDays($to) > 120) {
            $to = (clone $from)->addDays(120);
        }

        $tasks = Task::query()
            ->filtered($filters)
            ->overlapping($from, $to)
            ->with(['pic:id,name', 'ticket:id,number'])
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        return view('tasks.index', [
            'tasks' => $tasks,
            'planner' => $this->planner->build($tasks, $from, $to),
            'from' => $from,
            'to' => $to,
            'filters' => $filters,
            'list' => Task::query()
                ->filtered($filters)
                ->with(['pic:id,name', 'ticket:id,number'])
                ->orderByDesc('start_date')
                ->orderByDesc('id')
                ->paginate(15)
                ->withQueryString(),
            'stats' => $this->stats(),
            'statuses' => TaskStatus::options(),
            'priorities' => Priority::options(),
            'people' => $this->people(),
            'canManage' => $request->user()->hasPermission(Permission::ManageTasks),
            'canPublish' => $request->user()->hasPermission(Permission::PublishTasks),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permission::ManageTasks->value);

        $data = $this->validated($request);

        $task = Task::create($data + ['created_by' => $request->user()->id]);

        $this->log->log('task.created', "Membuat task “{$task->title}”", $task, [
            'start' => $task->start_date->toDateString(),
            'due' => $task->due_date->toDateString(),
            'public' => $task->is_public,
        ]);

        return back()->with('success', 'Task dibuat.');
    }

    public function show(Task $task): View
    {
        $this->authorize(Permission::ViewTasks->value);

        return view('tasks.show', [
            'task' => $task->load(['pic:id,name', 'creator:id,name', 'ticket']),
            // The days somebody actually reported working on this. The planner
            // records them and the report counts them; without this panel the
            // task's own page was the one place the record could not be read.
            'checks' => $task->checks()->with('checker:id,name')->orderByDesc('date')->get(),
            'statuses' => TaskStatus::options(),
            'priorities' => Priority::options(),
            'people' => $this->people(),
        ]);
    }

    public function update(Request $request, Task $task): RedirectResponse
    {
        $this->authorize(Permission::ManageTasks->value);

        $data = $this->validated($request);

        $changes = $this->changes($task, $data);

        $task->update($data);

        if ($changes !== []) {
            $this->log->log('task.updated', "Mengubah task “{$task->title}”", $task, ['changes' => $changes]);
        }

        return back()->with('success', 'Task diperbarui.');
    }

    public function destroy(Task $task): RedirectResponse
    {
        $this->authorize(Permission::ManageTasks->value);

        $title = $task->title;
        $task->delete();

        $this->log->log('task.deleted', "Menghapus task “{$title}”");

        return redirect()->route('tasks.index')->with('success', 'Task dihapus.');
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'start_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:start_date'],
            'status' => ['required', 'string', 'max:24'],
            'priority' => ['required', 'string', 'max:16'],
            'pic_id' => ['nullable', 'integer', 'exists:users,id'],
            'ticket_id' => ['nullable', 'integer', 'exists:tickets,id'],
            'progress' => ['nullable', 'integer', 'min:0', 'max:100'],
            'is_public' => ['nullable', 'boolean'],
            'attachment' => ['nullable', 'file', 'max:10240'],
        ], [], [
            'title' => 'judul',
            'start_date' => 'tanggal mulai',
            'due_date' => 'tanggal selesai',
            'status' => 'status',
            'priority' => 'prioritas',
        ]);

        $data['is_public'] = (bool) ($data['is_public'] ?? false);

        // Publishing a task to the public page is its own permission: a user
        // who may plan work is not automatically allowed to broadcast it.
        if ($data['is_public'] && ! $request->user()->hasPermission(Permission::PublishTasks)) {
            $data['is_public'] = false;
        }

        $data['progress'] = (int) ($data['progress'] ?? 0);

        // Only replace the stored file when a new one was actually sent, so
        // saving the form without re-picking the file does not wipe it.
        if ($file = $request->file('attachment')) {
            $data['attachment_path'] = $file->store('tasks', 'public');
            $data['attachment_name'] = $file->getClientOriginalName();
        }

        unset($data['attachment']);

        return $data;
    }

    private function date(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, int> */
    private function stats(): array
    {
        $counts = Task::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'total' => (int) $counts->sum(),
            'planned' => (int) ($counts[TaskStatus::Planned->value] ?? 0),
            'in_progress' => (int) ($counts[TaskStatus::InProgress->value] ?? 0),
            'completed' => (int) ($counts[TaskStatus::Completed->value] ?? 0),
            'public' => Task::where('is_public', true)->count(),
            'overdue' => Task::whereIn('status', [TaskStatus::Planned->value, TaskStatus::InProgress->value])
                ->whereDate('due_date', '<', now())
                ->count(),
        ];
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function people()
    {
        return User::active()->orderBy('name')->get(['id', 'name']);
    }
}
