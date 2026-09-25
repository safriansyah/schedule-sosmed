<?php

namespace Database\Seeders;

use App\Models\TicketCategory;
use Illuminate\Database\Seeder;

/**
 * A starting set of categories, drawn from the conditions in the brief.
 *
 * Idempotent (updateOrCreate on parent + slug), so re-seeding a live database
 * does not duplicate anything an admin has since renamed or added.
 */
class TicketCategorySeeder extends Seeder
{
    /** category => its sub-categories */
    private const TREE = [
        'Registrasi' => [
            'Belum registrasi',
            'Belum registrasi mata kuliah',
            'Gagal registrasi sistem',
            'Perubahan data registrasi',
        ],
        'Pembayaran' => [
            'Sudah registrasi belum bayar',
            'Billing NAC belum bayar',
            'Konfirmasi pembayaran',
            'Permohonan keringanan',
        ],
        'Akademik' => [
            'Jadwal tutorial',
            'Nilai & hasil ujian',
            'Bahan ajar',
        ],
        'Layanan & Informasi' => [
            'Pertanyaan umum',
            'Keluhan layanan',
            'Permintaan dihubungi',
        ],
        'Lainnya' => [
            'Tidak dapat dihubungi',
            'Mengundurkan diri',
        ],
    ];

    public function run(): void
    {
        $order = 0;

        foreach (self::TREE as $name => $children) {
            $parent = TicketCategory::updateOrCreate(
                ['parent_id' => null, 'slug' => str($name)->slug()->value()],
                ['name' => $name, 'sort_order' => $order += 10, 'is_active' => true],
            );

            $childOrder = 0;

            foreach ($children as $child) {
                TicketCategory::updateOrCreate(
                    ['parent_id' => $parent->id, 'slug' => str($child)->slug()->value()],
                    ['name' => $child, 'sort_order' => $childOrder += 10, 'is_active' => true],
                );
            }
        }
    }
}
