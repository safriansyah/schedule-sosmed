<?php

namespace App\Enums;

/**
 * Lifecycle of one case that needs handling.
 *
 * Open → Assigned → InProgress → FollowUp → Resolved → Closed. FollowUp is a
 * real status rather than an implied one because a ticket can sit there for
 * weeks (menunggu mahasiswa membayar) and that is different from someone
 * actively working it.
 *
 * Closed is terminal: the row stays, is still readable, and never leaves the
 * history.
 */
enum TicketStatus: string
{
    case Open = 'open';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case FollowUp = 'follow_up';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Assigned => 'Ditugaskan',
            self::InProgress => 'Dikerjakan',
            self::FollowUp => 'Follow Up',
            self::Resolved => 'Selesai',
            self::Closed => 'Ditutup',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Open => 'badge-amber',
            self::Assigned => 'badge-blue',
            self::InProgress => 'badge-cyan',
            self::FollowUp => 'badge-violet',
            self::Resolved => 'badge-green',
            self::Closed => 'badge-slate',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Open => 'sparkles',
            self::Assigned => 'user-plus',
            self::InProgress => 'refresh',
            self::FollowUp => 'phone',
            self::Resolved => 'check-circle',
            self::Closed => 'ban',
        };
    }

    /** Still in somebody's queue — drives the "open tickets" counters. */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Resolved, self::Closed], true);
    }

    /** Terminal states keep their resolution note and cannot be worked on. */
    /**
     * The coarse three-state view of a ticket: Open, Pending, Closed.
     *
     * The six statuses are right for an operator working one ticket; they are
     * too many for a report, where the question is only "is anyone on it, and
     * is it done". Reports group by this and drill down to the six.
     */
    public function stage(): string
    {
        return match ($this) {
            self::Open => 'open',
            self::Assigned, self::InProgress, self::FollowUp => 'pending',
            self::Resolved, self::Closed => 'closed',
        };
    }

    /** @return array<string, string> */
    public static function stages(): array
    {
        return [
            'open' => 'Open — belum ada yang memegang',
            'pending' => 'Pending — sedang ditangani',
            'closed' => 'Closed — selesai',
        ];
    }

    /**
     * The statuses inside one stage.
     *
     * @return array<int, string>
     */
    public static function inStage(string $stage): array
    {
        return array_values(array_map(
            fn (self $s) => $s->value,
            array_filter(self::cases(), fn (self $s) => $s->stage() === $stage),
        ));
    }

    public function isFinal(): bool
    {
        return $this === self::Closed;
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
