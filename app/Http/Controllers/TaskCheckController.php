<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Ticking one cell on the task planner.
 *
 * A toggle rather than separate add/remove actions: the cell has two states
 * and the grid is clicked quickly, so anything more than one round trip per
 * cell would be in the way.
 */
class TaskCheckController extends Controller
{
    public function __construct(private readonly ActivityLogger $log) {}

    public function toggle(Request $request, Task $task): RedirectResponse|JsonResponse
    {
        $this->authorize(Permission::ManageTasks->value);

        $data = $request->validate([
            'date' => ['required', 'date'],
        ], [], ['date' => 'tanggal']);

        $date = $request->date('date')->startOfDay();

        // Marking work on a day that is not yet planned would put a ✓ outside
        // the task's own bar, which reads as a mistake rather than as data.
        if ($task->start_date && $date->lt($task->start_date->startOfDay())) {
            return $this->respond(
                $request,
                $task,
                checked: false,
                message: 'Tanggal itu sebelum task dimulai.',
                failed: true,
            );
        }

        $existing = TaskCheck::where('task_id', $task->id)
            ->whereDate('date', $date->toDateString())
            ->first();

        if ($existing) {
            $existing->delete();

            $this->log->log('task.unchecked', "Batal centang {$task->title} — {$date->translatedFormat('j F Y')}", $task);

            return $this->respond($request, $task, checked: false, message: 'Centang dibatalkan.');
        }

        TaskCheck::create([
            'task_id' => $task->id,
            'date' => $date->toDateString(),
            'checked_by' => $request->user()->id,
        ]);

        $this->log->log('task.checked', "Centang {$task->title} — {$date->translatedFormat('j F Y')}", $task);

        return $this->respond($request, $task, checked: true, message: 'Aktivitas dicentang.');
    }

    /**
     * Answer in whatever form the caller asked for.
     *
     * The planner ticks cells over fetch, because a full page reload per click
     * throws away both the scroll position and the grid's horizontal scroll —
     * ticking five days used to mean five navigations and finding your place
     * again each time. The redirect is still here and still correct, so the
     * grid keeps working with JavaScript off.
     */
    private function respond(
        Request $request,
        Task $task,
        bool $checked,
        string $message,
        bool $failed = false,
    ): RedirectResponse|JsonResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'checked' => $checked,
                'message' => $message,
                'by' => $checked ? $request->user()->name : null,
                'at' => $checked ? now()->translatedFormat('j M Y, H:i') : null,
                // Deliberately no row total here. The grid counts ticks WITHIN
                // its date window, and this endpoint does not know that window
                // — an all-time count would quietly make the counter wrong.
                // The page derives it from the cells it already holds.
            ], $failed ? 422 : 200);
        }

        if ($failed) {
            return back()->withErrors(['date' => $message]);
        }

        return back()->with($checked ? 'success' : 'info', $message);
    }
}
