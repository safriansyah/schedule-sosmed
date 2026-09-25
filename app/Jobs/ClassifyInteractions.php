<?php

namespace App\Jobs;

use App\Enums\RoleName;
use App\Models\Interaction;
use App\Models\User;
use App\Notifications\UrgentInteractionNotification;
use App\Services\AI\ClassifierManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Works through the classifier backlog.
 *
 * Runs on the queue rather than in a web request because a batch can take tens
 * of seconds against the API, and nobody should wait on that. Capped per run
 * (config('crm.ai.per_run_limit')) so a large backlog is chewed through over
 * several runs instead of exhausting a day's free quota in one go.
 */
class ClassifyInteractions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 2;

    /** @param  array<int, string>|null  $ids  Specific interactions, or null for the backlog. */
    public function __construct(private readonly ?array $ids = null) {}

    /** @return array{classified:int, urgent:int, from_cache:int, from_llm:int} */
    public function handle(ClassifierManager $classifier): array
    {
        $pending = $this->pending();

        if ($pending->isEmpty()) {
            return ['classified' => 0, 'urgent' => 0, 'from_cache' => 0, 'from_llm' => 0];
        }

        // Ids of what was already urgent, so we only alert on NEW escalations —
        // a re-run must not re-notify everyone about the same comment.
        $alreadyUrgent = $pending->where('is_urgent', true)->pluck('id')->all();

        $stats = $classifier->handle($pending);

        $this->announce($pending->pluck('id')->all(), $alreadyUrgent);

        Log::info('Klasifikasi interaksi selesai', $stats);

        return $stats;
    }

    /** @return \Illuminate\Support\Collection<int, Interaction> */
    private function pending()
    {
        if ($this->ids !== null) {
            return Interaction::whereIn('id', $this->ids)->get();
        }

        return Interaction::query()
            ->unclassified()
            // Newest first: a fresh reputational attack matters far more than
            // a three-month-old comment still sitting in the backlog.
            ->latest('occurred_at')
            ->limit(max(1, (int) config('crm.ai.per_run_limit', 200)))
            ->get();
    }

    /**
     * Tell the people who handle the inbox about anything newly urgent.
     *
     * @param  array<int, string>  $processed
     * @param  array<int, string>  $alreadyUrgent
     */
    private function announce(array $processed, array $alreadyUrgent): void
    {
        $fresh = Interaction::whereIn('id', $processed)
            ->whereNotIn('id', $alreadyUrgent)
            ->where('is_urgent', true)
            ->get();

        if ($fresh->isEmpty()) {
            return;
        }

        $recipients = $this->handlers();

        if ($recipients->isEmpty()) {
            return;
        }

        foreach ($fresh as $interaction) {
            Notification::send($recipients, new UrgentInteractionNotification($interaction));
        }
    }

    /**
     * Who hears about an urgent comment: the roles that actually work the
     * inbox. The director is deliberately excluded — they see it on the
     * dashboard, and paging leadership on an unverified classifier result is
     * how false alarms become incidents.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function handlers()
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->whereIn('name', [
                RoleName::SuperAdmin->value,
                RoleName::Manager->value,
                RoleName::Pic->value,
                RoleName::Operator->value,
            ]))
            ->get();
    }
}
