<?php

namespace App\Enums;

/** How an incoming message reads. Assigned by the classifier, correctable by hand. */
enum Sentiment: string
{
    case Positive = 'positive';
    case Neutral = 'neutral';
    case Negative = 'negative';

    public function label(): string
    {
        return match ($this) {
            self::Positive => 'Positif',
            self::Neutral => 'Netral',
            self::Negative => 'Negatif',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Positive => 'badge-green',
            self::Neutral => 'badge-slate',
            self::Negative => 'badge-red',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Positive => 'thumbs-up',
            self::Neutral => 'minus',
            self::Negative => 'thumbs-down',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Positive => '#10b981',
            self::Neutral => '#94a3b8',
            self::Negative => '#f43f5e',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
