<?php

namespace App\Enums;

/**
 * The state one follow-up moved the ticket into — `statusfollowupid`.
 *
 * This is the short vocabulary an operator actually picks from while working:
 * four choices, not six. The ticket keeps the richer TicketStatus for
 * filtering and reporting, and toTicketStatus() is the single place the two
 * are reconciled, so they can never drift apart.
 *
 * Close is the one that cannot be chosen here: closing needs a resolution
 * note, so it goes through the Close Ticket form instead. It exists in this
 * enum because historic follow-ups record it.
 */
enum FollowUpStatus: string
{
    case New = 'new';
    case Assigned = 'assigned';
    case OnProses = 'on_proses';
    case Close = 'close';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Assigned => 'Assigned',
            self::OnProses => 'onProses',
            self::Close => 'Close',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::New => 'badge-amber',
            self::Assigned => 'badge-blue',
            self::OnProses => 'badge-cyan',
            self::Close => 'badge-green',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::New => 'sparkles',
            self::Assigned => 'user-plus',
            self::OnProses => 'refresh',
            self::Close => 'check-circle',
        };
    }

    /** The ticket status this follow-up status puts the ticket into. */
    public function toTicketStatus(): TicketStatus
    {
        return match ($this) {
            self::New => TicketStatus::Open,
            self::Assigned => TicketStatus::Assigned,
            // A follow-up that is still being worked lands on Follow Up rather
            // than In Progress: by definition somebody has just touched it.
            self::OnProses => TicketStatus::FollowUp,
            self::Close => TicketStatus::Resolved,
        };
    }

    /** The reverse view, for showing a ticket's state in the short vocabulary. */
    public static function fromTicketStatus(TicketStatus $status): self
    {
        return match ($status) {
            TicketStatus::Open => self::New,
            TicketStatus::Assigned => self::Assigned,
            TicketStatus::InProgress, TicketStatus::FollowUp => self::OnProses,
            TicketStatus::Resolved, TicketStatus::Closed => self::Close,
        };
    }

    /**
     * What the follow-up form offers. Close is excluded — see the class note.
     *
     * @return array<string, string> value => label
     */
    public static function selectable(): array
    {
        return collect(self::cases())
            ->reject(fn (self $s) => $s === self::Close)
            ->mapWithKeys(fn (self $s) => [$s->value => $s->label()])
            ->all();
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
