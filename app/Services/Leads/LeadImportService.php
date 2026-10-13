<?php

namespace App\Services\Leads;

use App\Enums\LeadSource;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Students\StudentRowMapper;
use App\Support\PhoneNumber;
use App\Support\Spreadsheet\CsvReader;
use App\Support\Spreadsheet\XlsxReader;
use Generator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Import of a lead list: upload → preview → import, like the student import.
 *
 * Lead lists are small next to the student list (hundreds to a few thousand
 * rows), so the import runs inside the confirm request instead of on the
 * queue — there is no worker to forget to start, and the admin sees the
 * result as soon as the page loads. Rows are still streamed and written in
 * chunks, so memory stays flat.
 *
 * One lead per phone number. A number already on file is filled in where
 * the new list knows more (a name, a city, a school) but keeps the source
 * it first came from.
 */
class LeadImportService
{
    /**
     * Spreadsheet headings that feed each field. Matched without case,
     * spaces or punctuation, so "No. HP", "no_hp" and Meta's "phone_number"
     * all land where they should.
     */
    public const MAPPING = [
        'nama' => ['nama', 'nama lengkap', 'name', 'full name', 'nama calon mahasiswa', 'nama peserta', 'nama siswa'],
        'no_hp' => ['no hp', 'nohp', 'nomor hp', 'hp', 'no wa', 'nomor wa', 'wa', 'whatsapp', 'no whatsapp', 'nomor whatsapp',
            'no handphone', 'nomor handphone', 'handphone', 'no telepon', 'telepon', 'no telp', 'phone', 'phone number', 'nomor telepon'],
        'kota' => ['kota kabupaten', 'kabupaten kota', 'kota kab', 'kab kota', 'kota', 'kabupaten', 'kab', 'kabko', 'domisili', 'city', 'asal kota'],
        'asal_sekolah' => ['asal sekolah', 'sekolah asal', 'nama sekolah', 'sekolah', 'school', 'asal instansi', 'instansi'],
    ];

    /** Columns the header row must have. Asal sekolah is optional. */
    public const REQUIRED = ['nama', 'no_hp', 'kota'];

    public const LABELS = [
        'nama' => 'Nama',
        'no_hp' => 'No HP',
        'kota' => 'Kota / Kabupaten',
        'asal_sekolah' => 'Asal Sekolah',
    ];

    private const CHUNK = 500;

    private const MAX_ROWS = 50000;

    private const PREVIEW_ROWS = 20;

    /** How far down to look for the heading row, past a title or blank rows. */
    private const HEADER_SCAN = 10;

    /** The reader behind rows(), for the row number Excel shows. */
    private CsvReader|XlsxReader|null $reader = null;

    public function __construct(private readonly ActivityLogger $log) {}

    /* -----------------------------------------------------------------
     | Upload & preview
     * ----------------------------------------------------------------- */

    public function store(UploadedFile $file, LeadSource $source, User $user): LeadImport
    {
        $extension = $this->realFormat($file);

        $path = $file->storeAs('lead-imports', Str::uuid()->toString().'.'.$extension, 'local');

        // A renamed .docx or .zip also starts with "PK": make sure there is a
        // worksheet to read before the upload is recorded at all.
        if ($extension === 'xlsx') {
            try {
                $readable = (new XlsxReader(Storage::disk('local')->path($path)))->sheetNames() !== [];
            } catch (Throwable) {
                $readable = false;
            }

            if (! $readable) {
                Storage::disk('local')->delete($path);

                throw new RuntimeException('Berkas ini bukan workbook Excel yang bisa dibaca (tidak ada lembar kerja). Buka di Excel lalu Simpan Sebagai .xlsx.');
            }
        }

        $import = LeadImport::create([
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'extension' => $extension,
            'source' => $source,
            'status' => LeadImport::STATUS_UPLOADED,
            'created_by' => $user->id,
        ]);

        $sheets = $this->sheetsFor($import);

        if ($sheets !== []) {
            $import->forceFill(['sheet' => $this->bestSheet($this->path($import), $sheets)])->save();
        }

        return $import;
    }

    /**
     * @return array{sheets: array<int, string>, headings: array<int, string>, mapping: array<string, int>, unmapped: array<int, string>, missing: array<int, string>, rows: array<int, array<int, string>>}
     */
    public function preview(LeadImport $import): array
    {
        $empty = ['sheets' => [], 'headings' => [], 'mapping' => [], 'unmapped' => [], 'missing' => self::REQUIRED, 'rows' => []];

        if (! is_file($this->path($import))) {
            return $empty;
        }

        $headings = null;
        $first = null;
        $scanned = 0;
        $rows = [];

        foreach ($this->rows($import) as $row) {
            if ($headings === null) {
                if ($this->isBlank($row)) {
                    continue;
                }

                $first ??= $row;

                if (self::isHeader($row)) {
                    $headings = $row;
                } elseif (++$scanned >= self::HEADER_SCAN) {
                    break;
                }

                continue;
            }

            if (! $this->isBlank($row)) {
                $rows[] = $row;
            }

            if (count($rows) >= self::PREVIEW_ROWS) {
                break;
            }
        }

        // No heading row found: show the first row, so the missing columns
        // are named.
        $headings ??= $first ?? [];
        $mapping = self::bind($headings);
        $import->forceFill(['mapping' => $mapping])->save();

        return [
            'sheets' => $this->sheetsFor($import),
            'headings' => $headings,
            'mapping' => $mapping,
            'unmapped' => array_values(array_filter(
                array_map('trim', array_diff_key($headings, array_flip($mapping))),
                fn ($h) => $h !== '',
            )),
            'missing' => array_values(array_diff(self::REQUIRED, array_keys($mapping))),
            'rows' => $rows,
        ];
    }

    /* -----------------------------------------------------------------
     | The import
     * ----------------------------------------------------------------- */

    public function import(LeadImport $import): void
    {
        @set_time_limit(0);

        $import->forceFill(['status' => LeadImport::STATUS_PROCESSING, 'error_message' => null])->save();

        try {
            if (! is_file($this->path($import))) {
                throw new RuntimeException('Berkas sumber tidak ditemukan lagi di server. Unggah ulang berkasnya.');
            }

            // All or nothing: a file that fails half-way leaves no half of it
            // behind, so fixing it and importing again is always safe.
            DB::transaction(fn () => $this->run($import));
        } catch (Throwable $e) {
            if (! $e instanceof RuntimeException) {
                report($e);
            }

            $import->forceFill([
                'status' => LeadImport::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
                'finished_at' => now(),
            ])->save();

            $this->log->log('lead.import_failed', "Import data lead gagal: {$import->original_name}", $import, [
                'error' => Str::limit($e->getMessage(), 300),
            ]);
        }
    }

    private function run(LeadImport $import): void
    {
        $counts = ['total' => 0, 'imported' => 0, 'updated' => 0, 'failed' => 0, 'duplicate' => 0];
        $errors = [];
        $seen = [];
        $buffer = [];
        $mapping = null;
        $extraColumns = [];
        $first = null;
        $scanned = 0;

        foreach ($this->rows($import) as $row) {
            if ($mapping === null) {
                if ($this->isBlank($row)) {
                    continue;
                }

                $first ??= $row;

                if (! self::isHeader($row)) {
                    if (++$scanned >= self::HEADER_SCAN) {
                        break;
                    }

                    continue;
                }

                $mapping = self::bind($row);

                $extraColumns = array_filter(
                    array_map('trim', array_diff_key($row, array_flip($mapping))),
                    fn ($h) => $h !== '',
                );
                $import->forceFill(['mapping' => $mapping])->save();

                continue;
            }

            // The row number Excel shows, blank rows included.
            $line = $this->reader->line;

            if ($this->isBlank($row)) {
                continue;
            }

            if (++$counts['total'] > self::MAX_ROWS) {
                throw new RuntimeException('Berkas melebihi batas '.number_format(self::MAX_ROWS).' baris. Pecah menjadi beberapa berkas.');
            }

            $value = fn (string $field) => isset($mapping[$field])
                ? (trim(preg_replace('/\s+/u', ' ', (string) ($row[$mapping[$field]] ?? '')) ?? '') ?: null)
                : null;

            $nama = $value('nama');
            $rawPhone = $value('no_hp');
            $phone = PhoneNumber::normalize($rawPhone);

            if ($nama === null) {
                $counts['failed']++;
                $this->error($errors, $line, $rawPhone, 'Nama kosong');

                continue;
            }

            if ($phone === null) {
                $counts['failed']++;
                $this->error($errors, $line, $rawPhone, $rawPhone === null ? 'No HP kosong' : 'No HP tidak valid (harus nomor HP Indonesia, contoh 0812…)');

                continue;
            }

            if (isset($seen[$phone])) {
                $counts['duplicate']++;
                $this->error($errors, $line, $rawPhone, 'No HP sama dengan baris '.$seen[$phone].' di berkas ini');

                continue;
            }

            $seen[$phone] = $line;

            $extra = [];
            foreach ($extraColumns as $position => $heading) {
                if (($cell = trim((string) ($row[$position] ?? ''))) !== '') {
                    $extra[$heading] = mb_substr($cell, 0, 1000);
                }
            }

            $buffer[$phone] = [
                'nama' => mb_substr($nama, 0, 255),
                'no_hp' => $phone,
                'kota' => ($kota = $value('kota')) ? mb_substr(Str::title($kota), 0, 255) : null,
                'asal_sekolah' => ($sekolah = $value('asal_sekolah')) ? mb_substr($sekolah, 0, 255) : null,
                'extra' => $extra ?: null,
            ];

            if (count($buffer) >= self::CHUNK) {
                $this->flush($buffer, $import, $counts);
                $buffer = [];
            }
        }

        if ($mapping === null) {
            if ($first === null) {
                throw new RuntimeException('Berkas kosong — tidak ada baris judul kolom.');
            }

            $missing = array_diff(self::REQUIRED, array_keys(self::bind($first)));

            throw new RuntimeException('Kolom wajib tidak ditemukan di berkas: '
                .implode(', ', array_map(fn ($f) => self::LABELS[$f], $missing)).'.');
        }

        if ($buffer !== []) {
            $this->flush($buffer, $import, $counts);
        }

        $import->forceFill([
            'status' => LeadImport::STATUS_COMPLETED,
            'total_rows' => $counts['total'],
            'imported_count' => $counts['imported'],
            'updated_count' => $counts['updated'],
            'failed_count' => $counts['failed'],
            'duplicate_count' => $counts['duplicate'],
            'errors' => $errors ?: null,
            'finished_at' => now(),
        ])->save();

        $this->log->log(
            'lead.imported',
            "Import {$counts['total']} baris data lead ({$import->source->label()}): {$counts['imported']} baru, {$counts['updated']} sudah ada, {$counts['failed']} gagal, {$counts['duplicate']} duplikat",
            $import,
            $counts + ['source' => $import->source->value],
        );
    }

    /**
     * New numbers are inserted with this list's source. Numbers already on
     * file get the blanks filled and the name refreshed; their source stays.
     *
     * @param  array<string, array<string, mixed>>  $buffer
     * @param  array<string, int>  $counts
     */
    private function flush(array $buffer, LeadImport $import, array &$counts): void
    {
        $existing = Lead::whereIn('no_hp', array_keys($buffer))->get()->keyBy('no_hp');
        $now = now();
        $insert = [];

        foreach ($buffer as $phone => $data) {
            $lead = $existing->get($phone);

            if ($lead === null) {
                $insert[] = [
                    ...$data,
                    'extra' => $data['extra'] === null ? null : json_encode($data['extra']),
                    'sumber' => $import->source->value,
                    'import_id' => $import->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                continue;
            }

            $lead->nama = $data['nama'];
            $lead->kota = $data['kota'] ?? $lead->kota;
            $lead->asal_sekolah = $data['asal_sekolah'] ?? $lead->asal_sekolah;
            $lead->extra = array_merge($lead->extra ?? [], $data['extra'] ?? []) ?: null;
            $lead->save();

            $counts['updated']++;
        }

        if ($insert !== []) {
            Lead::insert($insert);
            $counts['imported'] += count($insert);
        }
    }

    /* -----------------------------------------------------------------
     | Reading
     * ----------------------------------------------------------------- */

    /**
     * Heading positions per field. First matching column wins.
     *
     * @param  array<int, string>  $headings
     * @return array<string, int>
     */
    public static function bind(array $headings): array
    {
        $aliases = [];
        foreach (self::MAPPING as $field => $names) {
            foreach ($names as $name) {
                $aliases[StudentRowMapper::normalise($name)] ??= $field;
            }
        }

        $map = [];
        foreach ($headings as $position => $heading) {
            $field = $aliases[StudentRowMapper::normalise((string) $heading)] ?? null;

            if ($field !== null && ! isset($map[$field])) {
                $map[$field] = $position;
            }
        }

        return $map;
    }

    /** The heading row names every required column. */
    private static function isHeader(array $row): bool
    {
        return array_diff(self::REQUIRED, array_keys(self::bind($row))) === [];
    }

    /** @return Generator<int, array<int, string>> */
    private function rows(LeadImport $import): Generator
    {
        $this->reader = $import->extension === 'xlsx'
            ? new XlsxReader($this->path($import), $import->sheet)
            : new CsvReader($this->path($import));

        return $this->reader->rows();
    }

    /** @return array<int, string> */
    public function sheetsFor(LeadImport $import): array
    {
        $path = $this->path($import);

        return $import->extension === 'xlsx' && is_file($path) ? (new XlsxReader($path))->sheetNames() : [];
    }

    /** The tab whose header row maps the most required columns. */
    private function bestSheet(string $path, array $sheets): ?string
    {
        $best = $sheets[0] ?? null;
        $bestScore = -1;

        foreach ($sheets as $sheet) {
            // The heading row may sit under a title, so the best of the first
            // few rows counts.
            foreach ((new XlsxReader($path, $sheet))->rows() as $i => $row) {
                $map = self::bind($row);
                $score = count(array_intersect(self::REQUIRED, array_keys($map))) * 10 + count($map);

                if ($score > $bestScore) {
                    [$best, $bestScore] = [$sheet, $score];
                }

                if ($i + 1 >= self::HEADER_SCAN) {
                    break;
                }
            }
        }

        return $best;
    }

    /** By content, not by name; same rules as the student import. */
    private function realFormat(UploadedFile $file): string
    {
        $handle = @fopen($file->getRealPath(), 'rb');
        $head = $handle ? (string) fread($handle, 512) : '';

        if ($handle) {
            fclose($handle);
        }

        if (str_starts_with($head, "PK\x03\x04")) {
            return 'xlsx';
        }

        if (str_starts_with($head, "\xD0\xCF\x11\xE0")) {
            throw new RuntimeException(
                'Berkas ini format Excel lama (.xls 97-2003) atau dilindungi kata sandi. '
                .'Buka di Excel lalu Simpan Sebagai "Excel Workbook (.xlsx)" dan unggah ulang.'
            );
        }

        $text = ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $head) ?? $head);

        if ($text === '') {
            throw new RuntimeException('Berkas kosong.');
        }

        if (str_starts_with($text, '<') || str_contains($head, "\0")) {
            throw new RuntimeException('Isi berkas bukan tabel yang bisa dibaca. Buka di Excel lalu Simpan Sebagai .xlsx atau .csv.');
        }

        return 'csv';
    }

    private function path(LeadImport $import): string
    {
        return Storage::disk('local')->path($import->path);
    }

    /** @param array<int, string> $row */
    private function isBlank(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, array<string, mixed>> $errors */
    private function error(array &$errors, int $line, ?string $phone, string $reason): void
    {
        if (count($errors) < LeadImport::MAX_ERRORS) {
            $errors[] = array_filter(['line' => $line, 'no_hp' => $phone, 'reason' => $reason], fn ($v) => $v !== null);
        }
    }
}
