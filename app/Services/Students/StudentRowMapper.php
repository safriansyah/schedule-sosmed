<?php

namespace App\Services\Students;

use App\Enums\StudentCondition;
use App\Support\PhoneNumber;

/**
 * Turns one spreadsheet row into one `students` row.
 *
 * All the guessing lives here, in one place, driven by config/students.php —
 * so when the real file arrives the change is a list of header names, not a
 * rewrite. Two rules it never breaks:
 *
 *  1. Nothing from the file is discarded. Unrecognised columns go to `extra`.
 *  2. A row is rejected only for a missing REQUIRED column. Anything else that
 *     looks odd is imported and flagged, because a student we cannot contact
 *     is still a student we must not lose.
 */
class StudentRowMapper
{
    /** @var array<string, int> field => column position */
    private array $map = [];

    /** @var array<int, string> position => original heading, for `extra` */
    private array $unmapped = [];

    /**
     * Reads the header row and works out which column feeds which field.
     *
     * @param  array<int, string>  $headings
     */
    public function bindHeadings(array $headings): void
    {
        $this->map = [];
        $this->unmapped = [];

        $aliases = $this->aliasLookup();

        foreach ($headings as $position => $heading) {
            $key = self::normalise($heading);

            if ($key === '') {
                continue;
            }

            $field = $aliases[$key] ?? null;

            // First column wins: a file with both "Nama" and "Nama Lengkap"
            // maps the earlier one and keeps the other as extra data, rather
            // than letting the last column silently overwrite the first.
            if ($field !== null && ! isset($this->map[$field])) {
                $this->map[$field] = $position;

                continue;
            }

            $this->unmapped[$position] = trim($heading);
        }
    }

    /** @return array<string, int> */
    public function mapping(): array
    {
        return $this->map;
    }

    /** True when the header row has every column we cannot do without. */
    public function missingRequired(): array
    {
        return array_values(array_diff(
            (array) config('students.required', ['nim']),
            array_keys($this->map),
        ));
    }

    /**
     * One row → attributes ready for upsert, or a reason it cannot be used.
     *
     * @param  array<int, string>  $row
     * @return array{ok: bool, reason?: string, data?: array<string, mixed>}
     */
    public function map(array $row): array
    {
        $get = fn (string $field): ?string => $this->value($row, $field);

        $nim = $get('nim');

        if ($nim === null) {
            return ['ok' => false, 'reason' => 'NIM kosong'];
        }

        // Excel turns "010123456" into the number 10123456 and, for long
        // values, into "1.0123456E+8". Neither is a NIM, and silently storing
        // them would break every later match, so the row is rejected loudly.
        if (str_contains(strtoupper($nim), 'E+')) {
            return ['ok' => false, 'reason' => "NIM terbaca sebagai notasi ilmiah ({$nim}) — format kolom sebagai teks"];
        }

        $phoneRaw = $get('no_hp');
        $condition = $this->condition($get);

        return ['ok' => true, 'data' => [
            'nim' => $nim,
            'nac' => $get('nac'),
            'nama' => $get('nama'),
            'email' => $get('email'),
            'email_alternatif' => $get('email_alternatif'),
            'no_hp' => PhoneNumber::normalize($phoneRaw),
            'no_hp_raw' => $phoneRaw,
            'hp2' => $get('hp2'),
            'telp' => $get('telp'),
            'alamat' => $get('alamat'),

            // The source writes these as "code/Label"; the label is what a
            // human reads and filters on.
            'program_studi' => $this->label($get('program_studi')),
            'fakultas' => $this->label($get('fakultas')),
            'sipas' => $this->label($get('sipas')),
            'semester_terakhir' => $get('semester_terakhir'),
            'mri' => $get('mri'),
            'mra' => $get('mra'),

            'kabupaten' => $this->titleCase($this->label($get('kabupaten'))),
            'kecamatan' => $this->titleCase($get('kecamatan')),
            'kelurahan' => $this->titleCase($get('kelurahan')),
            'pokjar' => $this->label($get('pokjar')),
            'wilayah_ujian' => $this->label($get('wilayah_ujian')),

            'status_dp' => $get('status_dp'),
            'segmen' => $this->clean($get('segmen')),
            'petugas_nama' => $this->clean($get('petugas_nama')),
            'status_registrasi' => $get('status_registrasi'),
            'status_pembayaran' => $get('status_pembayaran'),
            'status_billing_nac' => $get('status_billing_nac'),
            'status_registrasi_matkul' => $get('status_registrasi_matkul'),
            'kategori_masalah' => $condition->value,
            'kategori_masalah_raw' => $get('kategori_masalah'),
            'sumber_data' => $get('sumber_data'),
            'catatan' => $get('catatan'),
            'extra' => $this->extra($row),
        ]];
    }

    /* -----------------------------------------------------------------
     | Reading one cell
     * ----------------------------------------------------------------- */

    /**
     * One mapped field, cleaned, or null.
     *
     * @param  array<int, string>  $row
     */
    private function value(array $row, string $field): ?string
    {
        $position = $this->map[$field] ?? null;

        return $position === null ? null : $this->clean($row[$position] ?? null);
    }

    /**
     * Strips what the source file wraps its values in.
     *
     * Two habits of the real export, both of which would otherwise become
     * data: every missing value is written as "/", and the Sub Katagori column
     * arrives quoted with a trailing comma — "'Non Aktif DN',".
     */
    private function clean(mixed $value): ?string
    {
        $text = trim((string) $value);

        // Default trim handles whitespace; this second pass removes the
        // quotes and trailing comma the source wraps values in, plus the run
        // of dots it pads some cells with ("Kec.Manggar.............").
        $text = rtrim(trim($text, "'\","), '.');

        if ($text === '') {
            return null;
        }

        $blanks = (array) config('students.blank_values', ['/']);

        return in_array(mb_strtolower($text), array_map('mb_strtolower', $blanks), true) ? null : $text;
    }

    /**
     * "88076/KAB. BANGKA" → "KAB. BANGKA".
     *
     * The export prefixes several columns with the institution's own numeric
     * code. The code is noise on screen and useless as a filter value, so the
     * label is kept.
     *
     * TWO separators, because the file genuinely uses both: 2.056 rows write
     * "88076/KAB. BANGKA" and 5.022 write "88076 | KAB. BANGKA". Handling only
     * the slash split one real kabupaten across two filter values — the exact
     * duplication this method exists to prevent.
     *
     * The code must be purely numeric for the split to happen. Without that
     * guard "NON POKJAR / NON SALUT" — a real pokjar name — would be chopped
     * to "NON SALUT".
     */
    private function label(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // ^ digits, then / or |, then the rest.
        if (preg_match('/^\s*(\d+)\s*[\/|]\s*(.+)$/u', $value, $matches) === 1) {
            return trim($matches[2]) !== '' ? trim($matches[2]) : $value;
        }

        return $value;
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    /**
     * The condition column if the file has one, otherwise inferred from the
     * four status flags — which is the common case, since the source system
     * exports states, not a summary.
     */
    private function condition(callable $get): StudentCondition
    {
        if ($raw = $get('kategori_masalah')) {
            $guess = StudentCondition::guess($raw);

            if ($guess !== StudentCondition::Other) {
                return $guess;
            }
        }

        // Every one of these columns is read the same way: "Sudah" means the
        // thing it names has been done, "Belum" means it has not. Keeping one
        // reading across all four is what makes the rules below predictable —
        // an inverted column would quietly swap two categories.
        $registrasi = $this->isYes($get('status_registrasi'));
        $bayar = $this->isYes($get('status_pembayaran'));
        $billingPaid = $this->isYes($get('status_billing_nac'));
        $matkul = $this->isYes($get('status_registrasi_matkul'));

        // Ordered most-blocking first: someone who never registered is "belum
        // registrasi", whatever their payment columns say.
        return match (true) {
            $registrasi === false => StudentCondition::OngoingTidakRegistrasi,
            $matkul === false => StudentCondition::MabaBelumRegMk,
            $billingPaid === false => StudentCondition::OngoingBillingPending,
            $bayar === false => StudentCondition::AdmisiTidakBayar,
            default => StudentCondition::Other,
        };
    }

    /**
     * Reads a yes/no cell in the several shapes a spreadsheet uses for it.
     * Null means "the file did not say", which is different from "no".
     */
    private function isYes(?string $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        $text = mb_strtolower(trim($value));

        return match (true) {
            in_array($text, ['sudah', 'ya', 'y', 'yes', '1', 'true', 'lunas', 'ada', 'sudah bayar'], true) => true,
            in_array($text, ['belum', 'tidak', 't', 'n', 'no', '0', 'false', 'belum bayar', 'kosong'], true) => false,
            // Free text: "sudah registrasi tapi belum bayar" in a status cell.
            str_starts_with($text, 'sudah') => true,
            str_starts_with($text, 'belum'), str_starts_with($text, 'tidak') => false,
            default => null,
        };
    }

    /**
     * Every column we did not map, keyed by its original heading.
     *
     * @param  array<int, string>  $row
     * @return array<string, string>|null
     */
    private function extra(array $row): ?array
    {
        $extra = [];

        foreach ($this->unmapped as $position => $heading) {
            $value = trim((string) ($row[$position] ?? ''));

            if ($value !== '') {
                $extra[$heading] = $value;
            }
        }

        return $extra ?: null;
    }

    /** "BANGKA TENGAH" and "bangka tengah" must land on the same filter value. */
    private function titleCase(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_convert_case(mb_strtolower(trim($value)), MB_CASE_TITLE, 'UTF-8');
    }

    /** @return array<string, string> normalised alias => field */
    private function aliasLookup(): array
    {
        $lookup = [];

        foreach ((array) config('students.mapping', []) as $field => $aliases) {
            // The field's own name always matches, so a file exported by this
            // app re-imports without any aliases being configured.
            foreach ([...$aliases, $field] as $alias) {
                $lookup[self::normalise($alias)] ??= $field;
            }
        }

        return $lookup;
    }

    /** "No. HP  " → "no hp". Punctuation and spacing carry no meaning here. */
    public static function normalise(string $heading): string
    {
        $text = mb_strtolower(trim($heading));
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }
}
