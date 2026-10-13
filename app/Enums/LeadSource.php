<?php

namespace App\Enums;

/** Where a lead list came from. Picked on the upload form, before importing. */
enum LeadSource: string
{
    case MetaAds = 'meta_ads';
    case AbsensiSosprom = 'absensi_sosprom';
    case TiktokAds = 'tiktok_ads';
    case Mitra = 'mitra';
    case UtPusat = 'ut_pusat';

    public function label(): string
    {
        return match ($this) {
            self::MetaAds => 'Iklan Meta Ads',
            self::AbsensiSosprom => 'Absensi Sosprom',
            self::TiktokAds => 'TikTok Ads',
            self::Mitra => 'Mitra',
            self::UtPusat => 'Data Lead dari UT Pusat',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::MetaAds => 'badge-blue',
            self::AbsensiSosprom => 'badge-amber',
            self::TiktokAds => 'badge-pink',
            self::Mitra => 'badge-violet',
            self::UtPusat => 'badge-green',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
