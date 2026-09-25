<?php

namespace App\Enums;

/**
 * How far along the handling of one message is.
 *
 * New → InProgress → Replied → Done, with Ignored as the escape hatch for
 * spam and noise so it leaves the queue without pretending it was answered.
 */
enum InteractionStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Replied = 'replied';
    case Done = 'done';
    case Ignored = 'ignored';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Baru',
            self::InProgress => 'Diproses',
            self::Replied => 'Dibalas',
            self::Done => 'Selesai',
            self::Ignored => 'Diabaikan',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::New => 'badge-amber',
            self::InProgress => 'badge-cyan',
            self::Replied => 'badge-blue',
            self::Done => 'badge-green',
            self::Ignored => 'badge-slate',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::New => 'sparkles',
            self::InProgress => 'refresh',
            self::Replied => 'reply',
            self::Done => 'check-circle',
            self::Ignored => 'ban',
        };
    }

    /** Statuses that still sit in someone's queue. */
    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::InProgress], true);
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
