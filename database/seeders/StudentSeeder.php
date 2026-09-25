<?php

namespace Database\Seeders;

use App\Enums\AssignmentStatus;
use App\Enums\StudentCondition;
use App\Models\Student;
use Illuminate\Database\Seeder;

/**
 * A small demo set, for a fresh install with no real data yet.
 *
 * SKIPS ITSELF once any student exists. The real list — 7.391 rows from
 * DATA INDUK PROGRES REGISTRASI — is imported through the Import screen, and
 * sprinkling ten invented students among them would be worse than useless.
 *
 * The values here now use the institution's real vocabulary (segmen, Sub
 * Katagori, Status DP) so that a demo database behaves like the live one.
 */
class StudentSeeder extends Seeder
{
    /** nim, nama, kabupaten, pokjar, segmen, kondisi, status DP */
    private const DEMO = [
        ['010123456', 'Ahmad Fauzan', 'KAB. BANGKA', 'SALUT TUNAS MUDA', 'Ongoing Proses Registrasi', StudentCondition::OngoingBillingPending, 'DA'],
        ['010123457', 'Budi Santoso', 'KAB. BANGKA', 'NON POKJAR / NON SALUT', 'Ongoing Proses Registrasi', StudentCondition::OngoingTidakRegistrasi, 'DA'],
        ['010123458', 'Citra Lestari', 'KAB. BANGKA TENGAH', 'SALUT PGK', 'Maba Proses Registrasi', StudentCondition::MabaBelumBayarMk, 'DS'],
        ['010123459', 'Dedi Saputra', 'KOTA PANGKAL PINANG', 'NON POKJAR / NON SALUT', 'Ongoing Proses Registrasi', StudentCondition::NonAktifDn, 'DN'],
        ['010123460', 'Eka Pratama', 'KAB. BANGKA', 'SALUT MEGA CENDIKIA', 'Maba Proses Registrasi', StudentCondition::AdmisiKurangBerkas, 'DS'],
        ['010123461', 'Fajar Hidayat', 'KAB. BANGKA SELATAN', 'NON POKJAR / NON SALUT', 'Ongoing Proses Registrasi', StudentCondition::AdmisiTidakBayar, 'DA'],
        ['010123462', 'Gina Maharani', 'KAB. BANGKA', 'SALUT TUNAS MUDA', 'Maba Proses Registrasi', StudentCondition::MabaBelumRegMk, 'DS'],
        ['010123463', 'Hendra Wijaya', 'KAB. BANGKA TENGAH', 'SALUT PGK', 'Ongoing Proses Registrasi', StudentCondition::NonAktifDn, 'DN'],
        ['010123464', 'Intan Permata', 'KOTA PANGKAL PINANG', 'NON POKJAR / NON SALUT', 'Maba Proses Registrasi', StudentCondition::AdmisiGagalValidasi, 'DS'],
        ['010123465', 'Joko Susanto', 'KAB. BANGKA BARAT', 'SALUT TUNAS MUDA', 'Ongoing Proses Registrasi', StudentCondition::OngoingBillingPending, 'DA'],
    ];

    private const PRODI = [
        'Manajemen', 'Akuntansi', 'Ilmu Komunikasi',
        'Pendidikan Guru Sekolah Dasar', 'Sistem Informasi',
    ];

    public function run(): void
    {
        // The live list is imported, not seeded. Never mix the two.
        if (Student::query()->exists()) {
            $this->command?->info('  Data mahasiswa sudah ada — demo dilewati.');

            return;
        }

        foreach (self::DEMO as $index => [$nim, $nama, $kabupaten, $pokjar, $segmen, $kondisi, $statusDp]) {
            Student::create([
                'nim' => $nim,
                'nama' => $nama,
                'email' => str($nama)->slug('.')->value().'@example.com',
                // Sequential, obviously fake numbers — nothing here should
                // reach a real person if someone runs a blast by mistake.
                'no_hp' => '62811100'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'no_hp_raw' => '0811100'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'program_studi' => self::PRODI[$index % count(self::PRODI)],
                'fakultas' => 'Fakultas Ekonomi dan Bisnis',
                'semester_terakhir' => '20261',
                'kabupaten' => $kabupaten,
                'pokjar' => $pokjar,
                'segmen' => $segmen,
                'status_dp' => $statusDp,
                'kategori_masalah' => $kondisi->value,
                'kategori_masalah_raw' => $kondisi->label(),
                'sumber_data' => 'Demo (bukan data asli)',
                'assignment_status' => AssignmentStatus::Unassigned->value,
                'assigned_to' => null,
            ]);
        }
    }
}
