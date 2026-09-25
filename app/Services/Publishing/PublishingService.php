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
        // Anything too far past its slot is failed BEFORE the normal run, so it
        // surfaces in the UI with a reason instead of silently never happening.
        $expired = $this->expireStale();

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

        return [
            'attempted' => $attempted,
            'published' => $published,
            'failed' => $failed,
            'expired' => $expired,
        ];
    }

    /**
     * Mark schedules that are past the safe delay window as failed.
     *
     * Fails closed on purpose. A post is written for a moment; publishing a
     * month-late promo as if it were current does more damage than not
     * publishing it, and the operator can always reschedule from the UI.
     *
     * @return int  Rows expired.
     */
    public function expireStale(): int
    {
        $stale = Schedule::stale()
            ->whereHas('content', fn ($q) => $q->where('status', ContentStatus::Scheduled->value))
            ->with('content:id,title')
            ->get();

        foreach ($stale as $schedule) {
            $late = $schedule->hoursLate();

            $schedule->markFailed(sprintf(
                'Dilewati otomatis: terlambat %s jam dari jadwal (batas aman %d jam). '
                .'Periksa apakah kontennya masih relevan, lalu jadwalkan ulang.',
                number_format($late, 1),
                (int) config('publishing.max_delay_hours'),
            ));

            $schedule->content?->update(['status' => ContentStatus::Failed]);

            Log::warning("Jadwal kedaluwarsa dilewati: {$schedule->content?->title} (telat {$late} jam)");

            $this->log->log(
                'schedule.expired',
                "\"{$schedule->content?->title}\" dilewati — terlambat {$late} jam dari jadwal",
                $schedule->content,
                ['scheduled_at' => $schedule->scheduled_at?->toIso8601String(), 'hours_late' => $late],
            );
        }

        return $stale->count();
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
