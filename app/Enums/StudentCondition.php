<?php

namespace App\Enums;

/**
 * Why a student is on the follow-up list.
 *
 * These are the institution's OWN categories, read from the "Sub Katagori"
 * column of DATA INDUK PROGRES REGISTRASI — not invented ones. The earlier
 * five-value set was a placeholder made before the file existed; the migration
 * 2026_09_19_000001 translates the old values onto these.
 *
 * `Other` stays as the catch-all so a sub-category nobody predicted is filed
 * rather than rejected, with the original text kept in `kategori_masalah_raw`.
 */
enum StudentCondition: string
{
    case NonAktifDn = 'non_aktif_dn';
    case OngoingTidakRegistrasi = 'ongoing_tidak_registrasi';
    case OngoingBillingPending = 'ongoing_billing_pending';
    case AdmisiTidakBayar = 'admisi_tidak_bayar';
    case AdmisiKurangBerkas = 'admisi_kurang_berkas';
    case AdmisiGagalValidasi = 'admisi_gagal_validasi';
    case MabaBelumBayarMk = 'maba_belum_bayar_mk';
    case MabaBelumRegMk = 'maba_belum_reg_mk';
    // Added when the institution began importing admission lists separately:
    // one of people admitted but not yet paid, one of the newly admitted.
    case AdmisiBelumBayar = 'admisi_belum_bayar';
    case AdmisiBaru = 'admisi_baru';
    case Other = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::NonAktifDn => 'Non Aktif DN',
            self::OngoingTidakRegistrasi => 'Ongoing Tidak Registrasi Semester Lalu',
            self::OngoingBillingPending => 'Ongoing Billing Pending Semester Lalu',
            self::AdmisiTidakBayar => 'Admisi Tidak Bayar Semester Lalu',
            self::AdmisiKurangBerkas => 'Admisi Kurang Berkas Semester Lalu',
            self::AdmisiGagalValidasi => 'Admisi Gagal Validasi Semester Lalu',
            self::MabaBelumBayarMk => 'Maba Belum Bayar Reg MK Semester Lalu',
            self::MabaBelumRegMk => 'Maba Belum Reg MK Semester Lalu',
            self::AdmisiBelumBayar => 'Admisi Belum Bayar',
            self::AdmisiBaru => 'Admisi Baru',
            self::Other => 'Kondisi lainnya',
        };
    }

    /** Fits a table column and a badge. */
    public function short(): string
    {
        return match ($this) {
            self::NonAktifDn => 'Non Aktif DN',
            self::OngoingTidakRegistrasi => 'Tidak Registrasi',
            self::OngoingBillingPending => 'Billing Pending',
            self::AdmisiTidakBayar => 'Admisi Tidak Bayar',
            self::AdmisiKurangBerkas => 'Kurang Berkas',
            self::AdmisiGagalValidasi => 'Gagal Validasi',
            self::MabaBelumBayarMk => 'Maba Belum Bayar',
            self::MabaBelumRegMk => 'Maba Belum Reg MK',
            self::AdmisiBelumBayar => 'Admisi Belum Bayar',
            self::AdmisiBaru => 'Admisi Baru',
            self::Other => 'Lainnya',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::NonAktifDn => 'badge-slate',
            self::OngoingTidakRegistrasi => 'badge-red',
            self::OngoingBillingPending => 'badge-amber',
            self::AdmisiTidakBayar => 'badge-pink',
            self::AdmisiKurangBerkas => 'badge-cyan',
            self::AdmisiGagalValidasi => 'badge-violet',
            self::MabaBelumBayarMk => 'badge-amber',
            self::MabaBelumRegMk => 'badge-blue',
            self::AdmisiBelumBayar => 'badge-rose',
            self::AdmisiBaru => 'badge-emerald',
            self::Other => 'badge-slate',
        };
    }

    /**
     * How urgently a ticket raised for this condition should be worked.
     *
     * Admission cases carry a deadline the institution does not control — miss
     * the window and the applicant is simply gone — so they outrank an ongoing
     * student who can still register next week. Read by
     * StudentTicketGenerator when it raises tickets in bulk.
     */
    public function priority(): Priority
    {
        return match ($this) {
            self::AdmisiKurangBerkas, self::AdmisiGagalValidasi => Priority::Urgent,
            // An admission with the money outstanding has a window that closes;
            // a brand-new admission is onboarding, not a problem, so it sits
            // below everything that is already going wrong.
            self::AdmisiBelumBayar => Priority::Urgent,
            self::AdmisiTidakBayar, self::MabaBelumBayarMk, self::MabaBelumRegMk => Priority::High,
            self::AdmisiBaru => Priority::Low,
            self::OngoingBillingPending, self::OngoingTidakRegistrasi => Priority::Normal,
            self::NonAktifDn, self::Other => Priority::Low,
        };
    }

    /**
     * Reads whatever the spreadsheet says.
     *
     * The source text arrives quoted and comma-suffixed ("'Non Aktif DN',")
     * and carries two typos that are in the file itself — "Semeter" and
     * "Semseter". Matching is therefore done on distinctive words rather than
     * on the whole string, so a corrected spelling keeps working too.
     */
    public static function guess(?string $raw): self
    {
        $text = mb_strtolower(trim((string) $raw, " \t\n\r\0\x0B'\","));

        if ($text === '' || $text === '/') {
            return self::Other;
        }

        if ($exact = self::tryFrom($text)) {
            return $exact;
        }

        $has = fn (string ...$needles) => array_reduce(
            $needles,
            fn ($carry, $needle) => $carry && str_contains($text, $needle),
            true,
        );

        return match (true) {
            $has('non aktif') => self::NonAktifDn,
            // Order matters inside each family: the more specific phrase first.
            $has('billing') => self::OngoingBillingPending,
            $has('ongoing', 'tidak registrasi') => self::OngoingTidakRegistrasi,
            $has('kurang berkas') => self::AdmisiKurangBerkas,
            // Both need the word "admisi", so "Maba Belum Bayar Reg MK" cannot
            // fall into them; and "belum bayar" is a different list from
            // "tidak bayar semester lalu", which is why they are separate.
            $has('admisi', 'belum bayar') => self::AdmisiBelumBayar,
            $has('admisi', 'baru') => self::AdmisiBaru,
            $has('gagal validasi') => self::AdmisiGagalValidasi,
            $has('admisi', 'tidak bayar') => self::AdmisiTidakBayar,
            $has('maba', 'belum bayar') => self::MabaBelumBayarMk,
            $has('maba', 'belum reg') => self::MabaBelumRegMk,
            default => self::Other,
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
