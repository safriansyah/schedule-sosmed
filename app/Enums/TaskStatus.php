<?php

namespace App\Enums;

/** Where a planned piece of work stands. Deliberately simpler than a ticket. */
enum TaskStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Direncanakan',
            self::InProgress => 'Berjalan',
            self::Completed => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Planned => 'badge-slate',
            self::InProgress => 'badge-cyan',
            self::Completed => 'badge-green',
            self::Cancelled => 'badge-red',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Planned => 'calendar',
            self::InProgress => 'refresh',
            self::Completed => 'check-circle',
            self::Cancelled => 'ban',
        };
    }

    /** Bar colour on the timeline. Hex, not a Tailwind class: the bars are
     *  positioned with inline styles anyway and this keeps them together. */
    public function barClass(): string
    {
        return match ($this) {
            self::Planned => 'bg-slate-400 dark:bg-slate-500',
            self::InProgress => 'bg-cyan-500',
            self::Completed => 'bg-emerald-500',
            self::Cancelled => 'bg-rose-400',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Planned, self::InProgress], true);
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
