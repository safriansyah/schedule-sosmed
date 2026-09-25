<?php

namespace App\Enums;

/** The four levels of Indonesian administrative division, outermost first. */
enum RegionLevel: string
{
    case Province = 'provinsi';
    case Regency = 'kabupaten';
    case District = 'kecamatan';
    case Village = 'desa';

    public function label(): string
    {
        return match ($this) {
            self::Province => 'Provinsi',
            self::Regency => 'Kabupaten / Kota',
            self::District => 'Kecamatan',
            self::Village => 'Desa / Kelurahan',
        };
    }

    /** Depth, 1-based — also the number of code segments ("34.04.05.2003"). */
    public function depth(): int
    {
        return match ($this) {
            self::Province => 1,
            self::Regency => 2,
            self::District => 3,
            self::Village => 4,
        };
    }

    public function child(): ?self
    {
        return match ($this) {
            self::Province => self::Regency,
            self::Regency => self::District,
            self::District => self::Village,
            self::Village => null,
        };
    }

    /** Level implied by a Kemendagri code, from how many segments it has. */
    public static function fromCode(string $code): self
    {
        return match (substr_count($code, '.')) {
            0 => self::Province,
            1 => self::Regency,
            2 => self::District,
            default => self::Village,
        };
    }
}
