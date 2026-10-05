<?php

namespace App\Enums;

/**
 * What a visitor came for, in two groups: Permintaan Layanan and Keluhan.
 *
 * The values of the original nine services are unchanged, so entries taken
 * before the split still read correctly; they all belong to Permintaan
 * Layanan. The group is derived from the case, never stored, so the two can
 * not disagree.
 */
enum GuestBookService: string
{
    // 1. Permintaan Layanan
    case PendaftaranMahasiswaBaru = 'pendaftaran_maba';
    case RegistrasiMatakuliah = 'registrasi_matakuliah';
    case RegistrasiMatakuliahTtm = 'registrasi_matakuliah_ttm';
    case PengambilanIjazah = 'pengambilan_ijazah';
    case LegalisirIjazah = 'legalisir_ijazah';
    case SuratKeterangan = 'surat_keterangan';
    case ResetAkun = 'reset_akun';
    case KonsultasiAkademik = 'konsultasi_akademik';
    case PerubahanDataPribadi = 'perubahan_data_pribadi';
    case LayananLainnya = 'layanan_lainnya';

    // 2. Keluhan
    case KeluhanNilai = 'keluhan_nilai';
    case KeluhanAlihKredit = 'keluhan_alih_kredit';
    case KeluhanKelulusan = 'keluhan_kelulusan';
    case KeluhanRalatDokumen = 'keluhan_ralat_dokumen';
    case KeluhanBahanAjar = 'keluhan_bahan_ajar';
    case KeluhanLainnya = 'keluhan_lainnya';

    public const TYPE_SERVICE = 'layanan';
    public const TYPE_COMPLAINT = 'keluhan';

    /** @return array<string, string> type => heading */
    public static function types(): array
    {
        return [
            self::TYPE_SERVICE => 'Permintaan Layanan',
            self::TYPE_COMPLAINT => 'Keluhan',
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::PendaftaranMahasiswaBaru => 'Pendaftaran Mahasiswa Baru',
            self::RegistrasiMatakuliah => 'Registrasi Matakuliah',
            self::RegistrasiMatakuliahTtm => 'Registrasi Matakuliah TTM',
            self::PengambilanIjazah => 'Pengambilan Ijazah',
            self::LegalisirIjazah => 'Legalisir Ijazah',
            self::SuratKeterangan => 'Surat Keterangan Mahasiswa/Alumni',
            self::ResetAkun => 'Reset Akun Mahasiswa',
            self::KonsultasiAkademik => 'Konsultasi Akademik',
            self::PerubahanDataPribadi => 'Perubahan Data Pribadi',
            self::LayananLainnya => 'Lainnya',
            self::KeluhanNilai => 'Permasalahan Nilai',
            self::KeluhanAlihKredit => 'Permasalahan Alih Kredit',
            self::KeluhanKelulusan => 'Kelulusan',
            self::KeluhanRalatDokumen => 'Ralat Ijazah, Transkrip Nilai, Surat Keterangan Pengganti Ijazah',
            self::KeluhanBahanAjar => 'Tracking Bahan Ajar',
            self::KeluhanLainnya => 'Lainnya',
        };
    }

    public function type(): string
    {
        return str_starts_with($this->value, 'keluhan_') ? self::TYPE_COMPLAINT : self::TYPE_SERVICE;
    }

    public function typeLabel(): string
    {
        return self::types()[$this->type()];
    }

    /** "Keluhan · Permasalahan Nilai" — the label with its group, for lists and exports. */
    public function fullLabel(): string
    {
        return $this->typeLabel().' · '.$this->label();
    }

    /** The "Lainnya" options, which need the description to say what it is. */
    public function isOther(): bool
    {
        return in_array($this, [self::LayananLainnya, self::KeluhanLainnya], true);
    }

    /** @return array<string, string> value => label, every option */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->fullLabel()])->all();
    }

    /** @return array<string, array<string, string>> type => [value => label] */
    public static function grouped(): array
    {
        $groups = array_fill_keys(array_keys(self::types()), []);

        foreach (self::cases() as $case) {
            $groups[$case->type()][$case->value] = $case->label();
        }

        return $groups;
    }

    /** @return array<int, string> every value of one type, for filtering */
    public static function valuesOf(string $type): array
    {
        return array_values(array_map(
            fn (self $s) => $s->value,
            array_filter(self::cases(), fn (self $s) => $s->type() === $type),
        ));
    }
}
