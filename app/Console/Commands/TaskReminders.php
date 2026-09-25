<?php

namespace App\Console\Commands;

use App\Enums\RoleName;
use App\Enums\TaskStatus;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Tell people about their task deadlines, once each morning.
 *
 * The planner already showed "Terlambat: 3" — on a page somebody has to
 * remember to open. Nothing in the app reached out, so a task could sail past
 * its deadline with the evidence sitting in plain sight and unread. This is
 * the only scheduled job the task module has, and it exists for that reason.
 *
 * Deliberately one digest per person rather than one message per task: the
 * bell is shared with urgent-comment alerts, and a module that floods it
 * makes the other one worthless too.
 */
class TaskReminders extends Command
{
    protected $signature = 'tasks:remind
                            {--force : Kirim ulang walau hari ini sudah pernah dikirim}';

    protected $description = 'Kirim pengingat task yang lewat tenggat atau jatuh tempo';

    /** Where the last send date is remembered, so a restart cannot double-send. */
    private const STAMP = 'tasks.remind.last_date';

    public function handle(): int
    {
        $today = now()->toDateString();

        if (! $this->option('force') && Setting::get(self::STAMP) === $today) {
            $this->line('Pengingat hari ini sudah dikirim. Pakai --force untuk mengirim ulang.');

            return self::SUCCESS;
        }

        $open = [TaskStatus::Planned->value, TaskStatus::InProgress->value];

        // One pass over the open tasks, grouped in PHP. The alternative is
        // three COUNT queries per person, which is a query storm on a table
        // this small.
        $tasks = Task::query()
            ->whereIn('status', $open)
            ->whereDate('due_date', '<=', now()->addDay()->toDateString())
            ->get(['id', 'pic_id', 'due_date']);

        if ($tasks->isEmpty()) {
            $this->info('Tidak ada task yang lewat tenggat atau jatuh tempo.');
            Setting::put(self::STAMP, $today);

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($tasks->groupBy('pic_id') as $picId => $group) {
            $counts = $this->tally($group);
            $recipients = $picId ? User::where('id', $picId)->where('is_active', true)->get() : $this->managers();

            if ($recipients->isEmpty()) {
                continue;
            }

            Notification::send($recipients, new \App\Notifications\TaskReminderNotification(
                overdue: $counts['overdue'],
                today: $counts['today'],
                soon: $counts['soon'],
                unowned: ! $picId,
            ));

            $sent += $recipients->count();
        }

        Setting::put(self::STAMP, $today);

        $this->info("Pengingat terkirim ke {$sent} penerima untuk {$tasks->count()} task.");

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Task>  $tasks
     * @return array{overdue: int, today: int, soon: int}
     */
    private function tally($tasks): array
    {
        $today = now()->startOfDay();

        return [
            'overdue' => $tasks->filter(fn (Task $t) => $t->due_date->lt($today))->count(),
            'today' => $tasks->filter(fn (Task $t) => $t->due_date->isSameDay($today))->count(),
            'soon' => $tasks->filter(fn (Task $t) => $t->due_date->gt($today))->count(),
        ];
    }

    /**
     * Who hears about a task nobody holds.
     *
     * The people who can actually hand it to someone — sending it to every
     * operator would be telling ten people about a decision only two of them
     * can make.
     */
    private function managers()
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->whereIn('name', [
                RoleName::SuperAdmin->value,
                RoleName::Manager->value,
            ]))
            ->get();
    }
}
