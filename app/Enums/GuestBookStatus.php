<?php

namespace App\Enums;

/**
 * Where a queue entry stands. Waiting → Called → Serving are the live states
 * the monitor shows; Done, Skipped and Ticketed take an entry off the screen
 * but keep it in the history.
 */
enum GuestBookStatus: string
{
    case Waiting = 'waiting';
    case Called = 'called';
    case Serving = 'serving';
    case Done = 'done';
    case Skipped = 'skipped';
    case Ticketed = 'ticketed';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Menunggu',
            self::Called => 'Dipanggil',
            self::Serving => 'Sedang Dilayani',
            self::Done => 'Selesai',
            self::Skipped => 'Dilewati',
            self::Ticketed => 'Jadi Tiket',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Waiting => 'badge-amber',
            self::Called => 'badge-blue',
            self::Serving => 'badge-cyan',
            self::Done => 'badge-green',
            self::Skipped => 'badge-slate',
            self::Ticketed => 'badge-violet',
        };
    }

    /** Still in the queue, so still on the monitor. */
    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    /** @return array<int, self> */
    public static function active(): array
    {
        return [self::Waiting, self::Called, self::Serving];
    }

    /**
     * Statuses an operator may set by hand. Ticketed is not one of them: it
     * is set only by "Add Ticket", together with the link to the ticket.
     *
     * @return array<int, self>
     */
    public static function settable(): array
    {
        return [self::Waiting, self::Called, self::Serving, self::Done, self::Skipped];
    }
}
