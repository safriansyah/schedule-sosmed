<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The morning nudge about task deadlines.
 *
 * ONE notification per person per day, summarising everything of theirs that
 * is late or falling due — not one per task. Ten late tasks would otherwise
 * produce ten bell entries, and a bell that floods is a bell nobody reads;
 * the app already has an urgent-comment notification competing for the same
 * attention.
 *
 * The payload mirrors WorkflowNotification's shape (title/body/icon/tone/url)
 * so the existing bell and notification page render it unchanged.
 */
class TaskReminderNotification extends Notification
{
    use Queueable;

    /**
     * @param  int  $overdue  past the due date and still open
     * @param  int  $today  due today
     * @param  int  $soon  due tomorrow
     * @param  bool  $unowned  this is the "nobody is holding these" digest
     */
    public function __construct(
        public readonly int $overdue,
        public readonly int $today,
        public readonly int $soon,
        public readonly bool $unowned = false,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'task.reminder',
            'title' => $this->title(),
            'body' => $this->body(),
            // Late is the only state worth colouring red; a task due today is
            // simply today's work, and dressing it as an alarm devalues the
            // colour for the things that are genuinely wrong.
            'icon' => $this->overdue > 0 ? 'alert' : 'calendar',
            'tone' => $this->overdue > 0 ? 'rose' : 'brand',
            'url' => route('tasks.index'),
        ];
    }

    private function title(): string
    {
        if ($this->unowned) {
            return 'Task tanpa PIC perlu dibagikan';
        }

        return $this->overdue > 0 ? 'Ada task yang lewat tenggat' : 'Task jatuh tempo';
    }

    private function body(): string
    {
        $parts = [];

        if ($this->overdue > 0) {
            $parts[] = "{$this->overdue} lewat tenggat";
        }

        if ($this->today > 0) {
            $parts[] = "{$this->today} jatuh tempo hari ini";
        }

        if ($this->soon > 0) {
            $parts[] = "{$this->soon} jatuh tempo besok";
        }

        $summary = implode(', ', $parts);

        return $this->unowned
            ? "Belum ada PIC: {$summary}."
            : "Task Anda: {$summary}.";
    }
}
