<?php

namespace App\Enums;

/** Jenis layanan on the Buku Tamu form, worded as the service desk writes them. */
enum GuestBookService: string
{
    case PendaftaranMahasiswaBaru = 'pendaftaran_maba';
    case RegistrasiMatakuliah = 'registrasi_matakuliah';
    case RegistrasiMatakuliahTtm = 'registrasi_matakuliah_ttm';
    case PengambilanIjazah = 'pengambilan_ijazah';
    case LegalisirIjazah = 'legalisir_ijazah';
    case SuratKeterangan = 'surat_keterangan';
    case ResetAkun = 'reset_akun';
    case KonsultasiAkademik = 'konsultasi_akademik';
    case PerubahanDataPribadi = 'perubahan_data_pribadi';

    public function label(): string
    {
        return match ($this) {
            self::PendaftaranMahasiswaBaru => 'PENDAFTARAN MAHASISWA BARU',
            self::RegistrasiMatakuliah => 'REGISTRASI MATAKULIAH',
            self::RegistrasiMatakuliahTtm => 'REGISTRASI MATAKULIAH TTM',
            self::PengambilanIjazah => 'PENGAMBILAN IJAZAH',
            self::LegalisirIjazah => 'LEGALISIR IJAZAH',
            self::SuratKeterangan => 'SURAT KETERANGAN MAHASISWA/ALUMNI',
            self::ResetAkun => 'RESET AKUN MAHASISWA',
            self::KonsultasiAkademik => 'KONSULTASI AKADEMIK',
            self::PerubahanDataPribadi => 'PERUBAHAN DATA PRIBADI',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
