<?php

namespace Database\Seeders;

use App\Enums\RegionLevel;
use App\Models\Region;
use Illuminate\Database\Seeder;

/**
 * The 38 Indonesian provinces, with their BPS/Kemendagri codes.
 *
 * Only the top level is seeded here. The full tree is ~90,000 rows and belongs
 * in a data file rather than in code — load it with:
 *
 *     php artisan regions:import path/to/wilayah.csv
 *
 * The importer accepts the standard "kode,nama" CSV (11 / 11.01 / 11.01.01 /
 * 11.01.01.2001) and derives the level from the code, so the provinces seeded
 * here are simply updated in place rather than duplicated.
 */
class RegionSeeder extends Seeder
{
    /** code => name */
    private const PROVINCES = [
        '11' => 'Aceh',
        '12' => 'Sumatera Utara',
        '13' => 'Sumatera Barat',
        '14' => 'Riau',
        '15' => 'Jambi',
        '16' => 'Sumatera Selatan',
        '17' => 'Bengkulu',
        '18' => 'Lampung',
        '19' => 'Kepulauan Bangka Belitung',
        '21' => 'Kepulauan Riau',
        '31' => 'DKI Jakarta',
        '32' => 'Jawa Barat',
        '33' => 'Jawa Tengah',
        '34' => 'DI Yogyakarta',
        '35' => 'Jawa Timur',
        '36' => 'Banten',
        '51' => 'Bali',
        '52' => 'Nusa Tenggara Barat',
        '53' => 'Nusa Tenggara Timur',
        '61' => 'Kalimantan Barat',
        '62' => 'Kalimantan Tengah',
        '63' => 'Kalimantan Selatan',
        '64' => 'Kalimantan Timur',
        '65' => 'Kalimantan Utara',
        '71' => 'Sulawesi Utara',
        '72' => 'Sulawesi Tengah',
        '73' => 'Sulawesi Selatan',
        '74' => 'Sulawesi Tenggara',
        '75' => 'Gorontalo',
        '76' => 'Sulawesi Barat',
        '81' => 'Maluku',
        '82' => 'Maluku Utara',
        '91' => 'Papua Barat',
        '92' => 'Papua Barat Daya',
        '94' => 'Papua',
        '95' => 'Papua Selatan',
        '96' => 'Papua Tengah',
        '97' => 'Papua Pegunungan',
    ];

    public function run(): void
    {
        foreach (self::PROVINCES as $code => $name) {
            Region::updateOrCreate(
                ['code' => $code],
                [
                    'parent_id' => null,
                    'level' => RegionLevel::Province->value,
                    'name' => $name,
                    'full_path' => $name,
                ],
            );
        }
    }
}
