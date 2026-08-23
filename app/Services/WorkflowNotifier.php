<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Models\Content;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Decides who hears about each workflow hand-off.
 *
 * Without this the queues are silent: a curator has no idea something is
 * waiting until they happen to open the Approval page.
 */
class WorkflowNotifier
{
    /** Creative submitted a draft → curators need to review it. */
    public function submitted(Content $content, User $actor): void
    {
        $this->send(
            $this->usersWithRole(RoleName::Curator),
            $content,
            'content.submitted',
            'Konten baru menunggu approval',
            "{$actor->name} mengirim \"{$content->title}\" untuk ditinjau.",
            'clock',
            'amber',
        );
    }

    /** Revision from verification was fixed → verifiers re-check it directly. */
    public function resubmittedToVerification(Content $content, User $actor): void
    {
        $this->send(
            $this->usersWithRole(RoleName::Verifier),
            $content,
            'content.submitted',
            'Revisi siap diverifikasi ulang',
            "{$actor->name} memperbaiki \"{$content->title}\" — kembali ke antrean verifikasi.",
            'badge-check',
            'cyan',
        );
    }

    /** Curator approved → verifiers are up next. */
    public function approved(Content $content, User $actor): void
    {
        $this->send(
            $this->usersWithRole(RoleName::Verifier),
            $content,
            'content.approved',
            'Konten menunggu verifikasi',
            "\"{$content->title}\" disetujui {$actor->name} dan siap diverifikasi.",
            'badge-check',
            'cyan',
        );

        $this->notifyCreator($content, 'content.approved', 'Konten Anda disetujui',
            "\"{$content->title}\" lolos approval.", 'check-circle', 'emerald');
    }

    /** Revision requested → back to the creative who made it. */
    public function revisionRequested(Content $content, User $actor, string $note): void
    {
        $this->notifyCreator($content, 'content.revision',
            'Konten Anda diminta revisi',
            "{$actor->name}: {$note}",
            'rotate', 'pink');
    }

    public function rejected(Content $content, User $actor, string $note): void
    {
        $this->notifyCreator($content, 'content.rejected',
            'Konten Anda ditolak',
            "{$actor->name}: {$note}",
            'x', 'rose');
    }

    /** Verified → the director watches the pipeline, the creator gets the good news. */
    public function verified(Content $content, User $actor): void
    {
        $this->notifyCreator($content, 'content.verified', 'Konten siap terbit',
            "\"{$content->title}\" lolos verifikasi dan masuk antrean terbit.", 'send', 'emerald');

        $this->send(
            $this->usersWithRole(RoleName::Director),
            $content,
            'content.verified',
            'Konten siap terbit',
            "\"{$content->title}\" sudah lolos seluruh tahap.",
            'send',
            'brand',
        );
    }

    /** Published — everyone involved should see it landed. */
    public function published(Content $content): void
    {
        $recipients = $this->usersWithRole(RoleName::Director, RoleName::SuperAdmin)
            ->concat(collect([$content->creator])->filter());

        $this->send(
            $recipients->unique('id'),
            $content,
            'content.published',
            'Konten berhasil terbit',
            "\"{$content->title}\" sudah tayang.",
            'send',
            'emerald',
        );
    }

    /** Publishing failed — admins must act, the creator should know. */
    public function publishFailed(Content $content, string $error): void
    {
        $recipients = $this->usersWithRole(RoleName::SuperAdmin)
            ->concat(collect([$content->creator])->filter());

        $this->send(
            $recipients->unique('id'),
            $content,
            'content.publish_failed',
            'Gagal menerbitkan konten',
            "\"{$content->title}\": {$error}",
            'alert',
            'rose',
        );
    }

    /* ----------------------------------------------------------------- */

    private function notifyCreator(
        Content $content,
        string $event,
        string $title,
        string $body,
        string $icon,
        string $tone,
    ): void {
        if ($content->creator) {
            $this->send(collect([$content->creator]), $content, $event, $title, $body, $icon, $tone);
        }
    }

    private function send(
        Collection $users,
        Content $content,
        string $event,
        string $title,
        string $body,
        string $icon,
        string $tone,
    ): void {
        $users = $users->filter(fn (?User $u) => $u?->is_active);

        if ($users->isEmpty()) {
            return;
        }

        Notification::send($users, new WorkflowNotification($event, $content, $title, $body, $icon, $tone));
    }

    private function usersWithRole(RoleName ...$roles): Collection
    {
        return User::active()
            ->whereHas('role', fn ($q) => $q->whereIn('name', array_column($roles, 'value')))
            ->get();
    }
}
