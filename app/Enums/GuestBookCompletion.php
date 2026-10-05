<?php

namespace App\Enums;

/**
 * The two choices made when an operator presses "Selesai" on a queue entry:
 * how the service was carried out (Proses Layanan) and how it ended
 * (Penyelesaian). Both start with "Langsung"; a new option is one line in
 * the matching list below and needs no migration (the columns are strings).
 */
final class GuestBookCompletion
{
    /** @return array<string, string> value => label */
    public static function processes(): array
    {
        return [
            'langsung' => 'Langsung',
        ];
    }

    /** @return array<string, string> value => label */
    public static function resolutions(): array
    {
        return [
            'langsung' => 'Langsung',
        ];
    }

    public static function processLabel(?string $value): ?string
    {
        return $value === null ? null : (self::processes()[$value] ?? $value);
    }

    public static function resolutionLabel(?string $value): ?string
    {
        return $value === null ? null : (self::resolutions()[$value] ?? $value);
    }
}
