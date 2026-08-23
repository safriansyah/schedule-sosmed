<?php

namespace App\Enums;

/**
 * Lifecycle of a piece of content.
 *
 * Draft → WaitingApproval → (Revision ⇄) → WaitingVerification
 *       → Verified → Scheduled → Published
 *
 * The curator's approval moves content straight to WaitingVerification, so the
 * `Approved` case exists as a label but is not a resting state in the machine.
 */
enum ContentStatus: string
{
    case Draft = 'draft';
    case WaitingApproval = 'waiting_approval';
    case Revision = 'revision';
    case Approved = 'approved';
    case WaitingVerification = 'waiting_verification';
    case Verified = 'verified';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::WaitingApproval => 'Menunggu Approval',
            self::Revision => 'Revisi',
            self::Approved => 'Disetujui',
            self::WaitingVerification => 'Menunggu Verifikasi',
            self::Verified => 'Terverifikasi',
            self::Scheduled => 'Terjadwal',
            self::Published => 'Terbit',
            self::Cancelled => 'Dibatalkan',
            self::Failed => 'Gagal',
        };
    }

    /** Tailwind badge utility used by <x-status-badge>. */
    public function badge(): string
    {
        return match ($this) {
            self::Draft => 'badge-slate',
            self::WaitingApproval => 'badge-amber',
            self::Revision => 'badge-pink',
            self::Approved => 'badge-blue',
            self::WaitingVerification => 'badge-cyan',
            self::Verified => 'badge-violet',
            self::Scheduled => 'badge-blue',
            self::Published => 'badge-green',
            self::Cancelled, self::Failed => 'badge-red',
        };
    }

    /** Hex colour used for calendar events and charts. */
    public function color(): string
    {
        return match ($this) {
            self::Draft => '#94a3b8',
            self::WaitingApproval => '#f59e0b',
            self::Revision => '#ec4899',
            self::Approved => '#3b82f6',
            self::WaitingVerification => '#06b6d4',
            self::Verified => '#8b5cf6',
            self::Scheduled => '#6366f1',
            self::Published => '#22c55e',
            self::Cancelled => '#64748b',
            self::Failed => '#ef4444',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Draft => 'file-text',
            self::WaitingApproval, self::WaitingVerification => 'clock',
            self::Revision => 'rotate',
            self::Approved => 'check-circle',
            self::Verified => 'badge-check',
            self::Scheduled => 'calendar',
            self::Published => 'send',
            self::Cancelled => 'x',
            self::Failed => 'alert',
        };
    }

    /** Creative may still edit the content in these states. */
    public function isEditableByCreative(): bool
    {
        return in_array($this, [self::Draft, self::Revision], true);
    }

    /** Terminal states — no further workflow transitions. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Published, self::Cancelled], true);
    }

    /** Statuses that still count as "in progress" for dashboards. */
    public static function pipeline(): array
    {
        return [
            self::WaitingApproval,
            self::Revision,
            self::WaitingVerification,
            self::Verified,
            self::Scheduled,
        ];
    }

    /** @return array<string, string> value => label, for select inputs */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $s) => [$s->value => $s->label()])
            ->all();
    }
}
