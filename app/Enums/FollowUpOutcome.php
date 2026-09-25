<?php

namespace App\Enums;

/** How a follow-up touch landed — the signal that moves a lead forward or drops it. */
enum FollowUpOutcome: string
{
    case Positive = 'positif';
    case Neutral = 'netral';
    case Negative = 'negatif';
    case Unclear = 'belum_jelas';

    public function label(): string
    {
        return match ($this) {
            self::Positive => 'Positif / Tertarik',
            self::Neutral => 'Netral',
            self::Negative => 'Negatif / Menolak',
            self::Unclear => 'Belum Jelas',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Positive => 'badge-green',
            self::Neutral => 'badge-slate',
            self::Negative => 'badge-red',
            self::Unclear => 'badge-amber',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $o) => [$o->value => $o->label()])->all();
    }
}
