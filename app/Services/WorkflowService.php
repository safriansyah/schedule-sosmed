<?php

namespace App\Services;

use App\Enums\ApprovalAction;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The content state machine.
 *
 *   Draft ─submit→ WaitingApproval ─approve→ WaitingVerification ─verify→ Verified
 *     ↑                   │                          │                      │
 *     └──── Revision ←────┴──────────────────────────┘                 schedule ↓
 *                                                                        Scheduled → Published
 *
 * Every transition is guarded, wrapped in a transaction, and written to the
 * activity log. Nothing else in the app may change `status` directly.
 */
class WorkflowService
{
    public function __construct(
        private readonly ActivityLogger $log,
        private readonly WorkflowNotifier $notifier,
    ) {}

    /** Creative sends a draft into the approval queue. */
    public function submit(Content $content, User $actor): Content
    {
        $this->guard($content, [ContentStatus::Draft, ContentStatus::Revision], 'dikirim untuk approval');

        return DB::transaction(function () use ($content, $actor) {
            $this->lockAndGuard($content, [ContentStatus::Draft, ContentStatus::Revision], 'dikirim untuk approval');

            $from = $content->status;

            // Where does this go next? A revision raised during verification
            // returns straight to verification — the curator already approved,
            // so re-approving the same content would be redundant. Everything
            // else (a fresh draft, or a revision raised during approval) enters
            // the approval queue.
            $openRevision = $content->revisions()->open()->latest()->first();
            $backToVerification = $openRevision?->return_to === 'verification'
                && $content->curated_by !== null;

            $target = $backToVerification
                ? ContentStatus::WaitingVerification
                : ContentStatus::WaitingApproval;

            // Resolve any open revision requests — the creative has responded.
            $content->revisions()->whereNull('resolved_at')->update([
                'resolved_at' => now(),
                'resolved_by' => $actor->id,
            ]);

            $content->update(['status' => $target]);

            $this->log->log(
                'content.submitted',
                "\"{$content->title}\" dikirim untuk ".($backToVerification ? 'verifikasi ulang' : 'approval'),
                $content,
                ['from' => $from->value, 'to' => $target->value],
            );

            $backToVerification
                ? $this->notifier->resubmittedToVerification($content, $actor)
                : $this->notifier->submitted($content, $actor);

            return $content->refresh();
        });
    }

    /** Curator approves — content moves on to the verification queue. */
    public function approve(Content $content, User $actor, ?string $note = null, array $suggestions = []): Content
    {
        $this->guard($content, [ContentStatus::WaitingApproval], 'disetujui');

        return DB::transaction(function () use ($content, $actor, $note, $suggestions) {
            $this->lockAndGuard($content, [ContentStatus::WaitingApproval], 'disetujui');

            $content->approvals()->create([
                'user_id' => $actor->id,
                'action' => ApprovalAction::Approved,
                'note' => $note,
                'suggested_caption' => $suggestions['caption'] ?? null,
                'suggested_hashtags' => $suggestions['hashtags'] ?? null,
                'suggested_schedule_at' => $suggestions['schedule_at'] ?? null,
            ]);

            $update = [
                'status' => ContentStatus::WaitingVerification,
                'curated_by' => $actor->id,
            ];

            // The curator owns the publish schedule — apply it to the content
            // when they set one during approval.
            if (! empty($suggestions['schedule_at'])) {
                $update['scheduled_at'] = $suggestions['schedule_at'];
            }

            $content->update($update);

            $this->log->log(
                'content.approved',
                "\"{$content->title}\" disetujui oleh {$actor->name}",
                $content,
                array_filter(['note' => $note]),
            );

            $this->notifier->approved($content, $actor);

            return $content->refresh();
        });
    }

    /** Curator rejects outright — the content is cancelled. */
    public function reject(Content $content, User $actor, string $note): Content
    {
        $this->guard($content, [ContentStatus::WaitingApproval], 'ditolak');

        return DB::transaction(function () use ($content, $actor, $note) {
            $this->lockAndGuard($content, [ContentStatus::WaitingApproval], 'ditolak');

            $content->approvals()->create([
                'user_id' => $actor->id,
                'action' => ApprovalAction::Rejected,
                'note' => $note,
            ]);

            $content->update([
                'status' => ContentStatus::Cancelled,
                'curated_by' => $actor->id,
            ]);

            $this->log->log(
                'content.rejected',
                "\"{$content->title}\" ditolak oleh {$actor->name}",
                $content,
                ['note' => $note],
            );

            $this->notifier->rejected($content, $actor, $note);

            return $content->refresh();
        });
    }

    /** Send the content back to the creative with notes. */
    public function requestRevision(Content $content, User $actor, string $note): Content
    {
        $this->guard(
            $content,
            [ContentStatus::WaitingApproval, ContentStatus::WaitingVerification],
            'diminta revisi',
        );

        return DB::transaction(function () use ($content, $actor, $note) {
            $this->lockAndGuard(
                $content,
                [ContentStatus::WaitingApproval, ContentStatus::WaitingVerification],
                'diminta revisi',
            );

            $fromVerification = $content->status === ContentStatus::WaitingVerification;

            if ($fromVerification) {
                $content->verifications()->create([
                    'user_id' => $actor->id,
                    'action' => ApprovalAction::Revision,
                    'note' => $note,
                ]);
            } else {
                $content->approvals()->create([
                    'user_id' => $actor->id,
                    'action' => ApprovalAction::Revision,
                    'note' => $note,
                ]);
            }

            $content->revisions()->create([
                'requested_by' => $actor->id,
                'note' => $note,
                'return_to' => $fromVerification ? 'verification' : 'approval',
            ]);

            $content->update(['status' => ContentStatus::Revision]);

            $this->log->log(
                'content.revision_requested',
                "\"{$content->title}\" diminta revisi oleh {$actor->name}",
                $content,
                ['note' => $note, 'stage' => $fromVerification ? 'verification' : 'approval'],
            );

            $this->notifier->revisionRequested($content, $actor, $note);

            return $content->refresh();
        });
    }

    /** Verifier signs off — content becomes ready to publish. */
    public function verify(Content $content, User $actor, array $checklist = [], ?string $note = null): Content
    {
        $this->guard($content, [ContentStatus::WaitingVerification], 'diverifikasi');

        return DB::transaction(function () use ($content, $actor, $checklist, $note) {
            $this->lockAndGuard($content, [ContentStatus::WaitingVerification], 'diverifikasi');

            $content->verifications()->create([
                'user_id' => $actor->id,
                'action' => ApprovalAction::Approved,
                'checklist' => $checklist,
                'note' => $note,
            ]);

            $content->update([
                'status' => ContentStatus::Verified,
                'verified_by' => $actor->id,
            ]);

            $this->log->log(
                'content.verified',
                "\"{$content->title}\" lolos verifikasi oleh {$actor->name}",
                $content,
                array_filter(['note' => $note]),
            );

            $this->notifier->verified($content, $actor);

            // A verified post with a date on it goes straight into the queue.
            return $content->scheduled_at
                ? $this->schedule($content->refresh(), $actor)
                : $content->refresh();
        });
    }

    /** Move verified content into the publishing queue. */
    public function schedule(Content $content, User $actor): Content
    {
        $this->guard($content, [ContentStatus::Verified], 'dijadwalkan');

        if (! $content->scheduled_at) {
            throw new RuntimeException('Konten belum memiliki jadwal terbit.');
        }

        return DB::transaction(function () use ($content, $actor) {
            $this->lockAndGuard($content, [ContentStatus::Verified], 'dijadwalkan');

            $content->update(['status' => ContentStatus::Scheduled]);

            // Mirror the status onto each target account's schedule row.
            $content->schedules()->update([
                'status' => ContentStatus::Scheduled,
                'scheduled_at' => $content->scheduled_at,
            ]);

            $this->log->log(
                'content.scheduled',
                "\"{$content->title}\" dijadwalkan terbit {$content->scheduled_at->translatedFormat('d M Y H:i')}",
                $content,
                ['scheduled_at' => $content->scheduled_at->toIso8601String()],
            );

            return $content->refresh();
        });
    }

    /** Cancel content at any point before it goes out. */
    public function cancel(Content $content, User $actor, ?string $note = null): Content
    {
        if ($content->status->isFinal()) {
            throw new RuntimeException('Konten sudah final dan tidak dapat dibatalkan.');
        }

        return DB::transaction(function () use ($content, $actor, $note) {
            $raw = Content::whereKey($content->getKey())->lockForUpdate()->value('status');
            $current = $raw instanceof ContentStatus ? $raw : ContentStatus::from((string) $raw);

            if ($current->isFinal()) {
                throw new RuntimeException('Konten sudah final dan tidak dapat dibatalkan.');
            }

            $content->update(['status' => ContentStatus::Cancelled]);
            $content->schedules()->update(['status' => ContentStatus::Cancelled]);

            $this->log->log(
                'content.cancelled',
                "\"{$content->title}\" dibatalkan oleh {$actor->name}",
                $content,
                array_filter(['note' => $note]),
            );

            return $content->refresh();
        });
    }

    /**
     * Guard a transition, producing a readable error instead of a silent
     * illegal state change. Uses the in-memory status for a fast fail before
     * a transaction is opened.
     *
     * @param  array<int, ContentStatus>  $allowed
     */
    private function guard(Content $content, array $allowed, string $intent): void
    {
        $this->assert($content->status, $allowed, $intent);
    }

    /**
     * The race-safe guard: re-read the row under a write lock inside the
     * transaction and re-assert the status. Two curators (or verifiers) hitting
     * the same content at once are serialized here — the first commits the
     * transition, the second sees the new status and is rejected cleanly
     * instead of both decisions landing.
     *
     * @param  array<int, ContentStatus>  $allowed
     */
    private function lockAndGuard(Content $content, array $allowed, string $intent): void
    {
        $raw = Content::whereKey($content->getKey())->lockForUpdate()->value('status');

        $current = $raw instanceof ContentStatus ? $raw : ContentStatus::from((string) $raw);

        $this->assert($current, $allowed, $intent);
    }

    /**
     * @param  array<int, ContentStatus>  $allowed
     */
    private function assert(ContentStatus $current, array $allowed, string $intent): void
    {
        if (! in_array($current, $allowed, true)) {
            $expected = collect($allowed)->map(fn (ContentStatus $s) => $s->label())->implode(' atau ');

            throw new RuntimeException(
                "Konten berstatus \"{$current->label()}\" tidak dapat {$intent}. Diharapkan: {$expected}."
            );
        }
    }
}
