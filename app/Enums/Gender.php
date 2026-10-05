<?php

namespace App\Enums;

enum Gender: string
{
    case Male = 'laki_laki';
    case Female = 'perempuan';

    public function label(): string
    {
        return match ($this) {
            self::Male => 'Laki-laki',
            self::Female => 'Perempuan',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $g) => [$g->value => $g->label()])->all();
    }
}
