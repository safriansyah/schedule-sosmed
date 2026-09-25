<?php

namespace App\Http\Controllers;

use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public task board — the ONLY unauthenticated page in the application.
 *
 * Three layers keep student data off it, deliberately overlapping:
 *
 *  1. Only `is_public` tasks are queried at all.
 *  2. The query selects a fixed column list. A column added to `tasks` later
 *     is invisible here until someone deliberately adds it.
 *  3. The view renders from Task::publicPayload(), a whitelist of six fields.
 *
 * Tasks have no relation to students or contacts in the first place, so there
 * is no personal data within reach — but the brief is emphatic about this page,
 * and defence in depth costs nothing here.
 *
 * Note what is NOT exposed even though it exists on the model: the PIC's name,
 * the creator, the linked ticket number, and any attachment. Those are internal.
 */
class PublicTaskController extends Controller
{
    public function index(Request $request): View
    {
        $tasks = Task::query()
            ->public()
            // Cancelled work is not something to advertise; it would read as a
            // promise that was broken rather than a plan that changed.
            ->whereIn('status', [
                TaskStatus::Planned->value,
                TaskStatus::InProgress->value,
                TaskStatus::Completed->value,
            ])
            ->orderBy('start_date')
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'title', 'description', 'start_date', 'due_date', 'status', 'progress']);

        return view('public.tasks', [
            'groups' => $tasks
                ->map(fn (Task $task) => $task->publicPayload())
                ->groupBy(fn (array $task) => $task['start_date']->isoFormat('MMMM Y')),
            'count' => $tasks->count(),
        ]);
    }
}
