<?php

namespace App\Services\Publishing;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Schedule;
use App\Services\ActivityLogger;
use App\Services\WorkflowNotifier;
use App\Services\Publishing\Contracts\PlatformPublisher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Drives publishing: picks up due schedules, hands each to the right platform
 * publisher, and records the outcome on both the schedule and its content.
 *
 * Each schedule is isolated — one failing account never blocks the others.
 */
class PublishingService
{
    /** @var Collection<string, PlatformPublisher> */
    private Collection $publishers;

    public function __construct(
        private readonly ActivityLogger $log,
        private readonly WorkflowNotifier $notifier,
    )
    {
        $this->publishers = collect([new InstagramPublisher])
            ->keyBy(fn (PlatformPublisher $p) => $p->platform()->value);
    }

    /**
     * Publish everything that is due.
     *
     * @return array{attempted: int, published: int, failed: int}
     */
    public function publishDue(): array
    {
        $schedules = Schedule::due()
            ->whereHas('content', fn ($q) => $q->where('status', ContentStatus::Scheduled->value))
            ->with(['content.media', 'account'])
            ->get();

        $published = 0;
        $failed = 0;

        $attempted = 0;

        foreach ($schedules as $schedule) {
            // Skip rows another run already claimed.
            if (! $schedule->claim()) {
                continue;
            }

            $attempted++;
            $this->publish($schedule) ? $published++ : $failed++;
        }

        return ['attempted' => $attempted, 'published' => $published, 'failed' => $failed];
    }

    /** Publish a single schedule. Returns true on success. */
    public function publish(Schedule $schedule): bool
    {
        try {
            $publisher = $this->publisherFor($schedule);

            $result = $publisher->publish($schedule, $schedule->content->composedCaption());

            $schedule->markPublished($result['external_id'], $result['permalink'] ?? null);

            $this->log->log(
                'content.published',
                "\"{$schedule->content->title}\" terbit di {$schedule->account->name}",
                $schedule->content,
                ['account' => $schedule->account->name, 'external_id' => $result['external_id']],
            );

            $this->settleContent($schedule->content);

            return true;
        } catch (Throwable $e) {
            $schedule->markFailed($e->getMessage());

            $schedule->content->forceFill([
                'status' => ContentStatus::Failed,
                'last_error' => $e->getMessage(),
            ])->save();

            Log::error("Gagal menerbitkan schedule {$schedule->id}: ".$e->getMessage());

            $this->log->log(
                'content.publish_failed',
                "Gagal menerbitkan \"{$schedule->content->title}\" ke {$schedule->account->name}",
                $schedule->content,
                ['error' => $e->getMessage()],
            );

            $this->notifier->publishFailed($schedule->content, $e->getMessage());

            return false;
        }
    }

    /**
     * Content is only "Published" once every target account succeeded; while
     * some are still pending it stays Scheduled so they get retried.
     */
    private function settleContent(Content $content): void
    {
        $pending = $content->schedules()
            ->where('status', '!=', ContentStatus::Published->value)
            ->count();

        if ($pending === 0) {
            $content->forceFill([
                'status' => ContentStatus::Published,
                'published_at' => now(),
                'last_error' => null,
            ])->save();

            $this->notifier->published($content);
        }
    }

    private function publisherFor(Schedule $schedule): PlatformPublisher
    {
        $platform = $schedule->account->platform;

        return $this->publishers->get($platform->value)
            ?? throw new RuntimeException("Publisher untuk {$platform->label()} belum tersedia.");
    }
}
