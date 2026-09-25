<?php

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The planner grid: tasks down the side, dates across the top, a ✓ in the cell.
 *
 * Replaces the Gantt bars. The bars were accurate and nobody read them — a
 * proportional bar answers "how long is this scheduled for", while the
 * question the team actually asks at a stand-up is "did this happen on
 * Tuesday". A grid of ticks answers that one, and it is the same shape as the
 * spreadsheet the team kept before this app existed.
 *
 * Each cell knows two things, and they are not the same thing:
 *
 *   planned — the day falls inside the task's start…due range
 *   checked — somebody recorded work on that day (a `task_checks` row)
 *
 * Keeping them apart is what makes the grid worth reading: a planned day with
 * no tick is work that slipped, and a tick outside the plan is work that was
 * not scheduled. Merging them would hide both.
 */
class TaskPlanner
{
    /**
     * @param  Collection<int, Task>  $tasks
     * @return array{
     *     days: array<int, array{date: Carbon, iso: string, label: string, weekday: string, today: bool, weekend: bool, first_of_month: bool, month: string}>,
     *     months: array<int, array{label: string, span: int}>,
     *     rows: array<int, array{task: Task, cells: array<int, array{iso: string, planned: bool, checked: bool, today: bool, weekend: bool, by: ?string, at: ?string}>, done: int, planned: int}>,
     *     total: int
     * }
     */
    public function build(Collection $tasks, Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        // Inclusive: a window of 17–17 Sep is one day, not zero.
        $total = (int) $from->diffInDays($to) + 1;

        $days = [];

        for ($i = 0; $i < $total; $i++) {
            $day = $from->copy()->addDays($i);

            $days[] = [
                'date' => $day,
                'iso' => $day->toDateString(),
                // Just the day number across the top — the month is printed
                // once, where it changes, so 30 columns stay readable.
                'label' => $day->format('j'),
                'weekday' => $day->isoFormat('dd'),
                'today' => $day->isToday(),
                'weekend' => $day->isWeekend(),
                'first_of_month' => $i === 0 || $day->day === 1,
                'month' => $day->translatedFormat('M Y'),
            ];
        }

        $checks = $this->checksFor($tasks, $from, $to);

        $rows = $tasks->map(function (Task $task) use ($days, $checks) {
            $ticked = $checks[$task->id] ?? [];
            $cells = [];
            $planned = 0;
            $done = 0;

            foreach ($days as $day) {
                $isPlanned = $task->start_date !== null
                    && $task->due_date !== null
                    && $day['iso'] >= $task->start_date->toDateString()
                    && $day['iso'] <= $task->due_date->toDateString();

                $isChecked = isset($ticked[$day['iso']]);

                $planned += $isPlanned ? 1 : 0;
                $done += $isChecked ? 1 : 0;

                $cells[] = [
                    'iso' => $day['iso'],
                    'planned' => $isPlanned,
                    'checked' => $isChecked,
                    'today' => $day['today'],
                    'weekend' => $day['weekend'],
                    // Who reported the work, so a tick is answerable for.
                    'by' => $isChecked ? ($ticked[$day['iso']]['by'] ?? null) : null,
                    'at' => $isChecked ? ($ticked[$day['iso']]['at'] ?? null) : null,
                ];
            }

            return ['task' => $task, 'cells' => $cells, 'done' => $done, 'planned' => $planned];
        })->values()->all();

        return ['days' => $days, 'months' => $this->months($days), 'rows' => $rows, 'total' => $total];
    }

    /**
     * The month bands above the day numbers.
     *
     * A month name cannot live in a day column: "Sep 2026" is about 40px wide
     * and a day column is 36px, so it spilled into its neighbour and was
     * clipped by the fixed row height — it read as a rendering fault rather
     * than as a label. Spanning the days it covers is what a table header is
     * for, and it also tells you at a glance how much of the window each month
     * takes up.
     *
     * @param  array<int, array<string, mixed>>  $days
     * @return array<int, array{label: string, span: int}>
     */
    private function months(array $days): array
    {
        $bands = [];

        foreach ($days as $day) {
            $last = array_key_last($bands);

            if ($last !== null && $bands[$last]['label'] === $day['month']) {
                $bands[$last]['span']++;

                continue;
            }

            $bands[] = ['label' => $day['month'], 'span' => 1];
        }

        return $bands;
    }

    /**
     * Every tick in the window, in one query.
     *
     * A grid of 20 tasks × 30 days is 600 cells; asking per cell, or even per
     * row, is how a planner becomes the slowest page in the app. The reporter's
     * name comes along in the same statement rather than through a relation,
     * for the same reason.
     *
     * @param  Collection<int, Task>  $tasks
     * @return array<int, array<string, array{by: ?string, at: ?string}>>
     */
    private function checksFor(Collection $tasks, Carbon $from, Carbon $to): array
    {
        $ids = $tasks->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $out = [];

        TaskCheck::query()
            ->whereIn('task_checks.task_id', $ids)
            ->whereBetween('task_checks.date', [$from->toDateString(), $to->toDateString()])
            ->leftJoin('users', 'users.id', '=', 'task_checks.checked_by')
            ->get(['task_checks.task_id', 'task_checks.date', 'task_checks.created_at', 'users.name as checker'])
            ->each(function ($check) use (&$out) {
                $out[$check->task_id][Carbon::parse($check->date)->toDateString()] = [
                    'by' => $check->checker,
                    'at' => $check->created_at?->translatedFormat('j M Y, H:i'),
                ];
            });

        return $out;
    }
}
