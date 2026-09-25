<?php

namespace App\Enums;

/**
 * Shared by tickets and tasks — urgency means the same thing in both, and two
 * parallel enums would drift apart the first time someone added a level.
 */
enum Priority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Rendah',
            self::Normal => 'Normal',
            self::High => 'Tinggi',
            self::Urgent => 'Mendesak',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Low => 'badge-slate',
            self::Normal => 'badge-blue',
            self::High => 'badge-amber',
            self::Urgent => 'badge-red',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Low => 'minus',
            self::Normal => 'hash',
            self::High => 'trend',
            self::Urgent => 'flame',
        };
    }

    /** Higher sorts first in queues. */
    public function weight(): int
    {
        return match ($this) {
            self::Urgent => 3,
            self::High => 2,
            self::Normal => 1,
            self::Low => 0,
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
