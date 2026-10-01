<?php

namespace App\Services\Students;

use App\Jobs\ProcessStudentImport;
use App\Models\Student;
use App\Models\StudentImport;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Spreadsheet\CsvReader;
use App\Support\Spreadsheet\XlsxReader;
use Generator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Bulk import of the student list.
 *
 * Built for size from the start: rows are streamed, never collected into an
 * array, and written in chunked upserts. Memory use is flat whether the file
 * has 10 rows or 50.000 — the brief's 10 dummy students are a test case, not
 * the design target.
 *
 * Assignment rule, and its one exception:
 *
 *  - A re-import NEVER moves an existing student. Whoever holds them keeps
 *    them, however the spreadsheet has changed.
 *  - A NEW student is assigned only when the file's "Petugas Nama" matches an
 *    existing user EXACTLY. That is the officer the institution already put on
 *    the case, so honouring it is not the system guessing.
 *  - Everyone else lands on `belum_assigned`, and the admin hands them out.
 *
 * No user account is ever created from a spreadsheet: a name in a cell is not
 * grounds for a login.
 */
class StudentImportService
{
    /** @var array<string, int>|null Cached name => user id. */
    private ?array $officers = null;

    /** @var array<string, int>|null Cached column => max length. */
    private ?array $limits = null;

    public function __construct(
        private readonly StudentRowMapper $mapper,
        private readonly ActivityLogger $log,
    ) {}

    /* -----------------------------------------------------------------
     | Upload & preview
     * ----------------------------------------------------------------- */

    /**
     * Stores the upload and returns a preview — headings, how they were
     * mapped, and the first rows — WITHOUT importing anything.
     *
     * The admin confirms the mapping before a single row is written, because
     * a wrong guess on 5.000 rows is far more expensive to undo than to catch.
     *
     * @return array{import: StudentImport, headings: array<int, string>, mapping: array<string, int>, unmapped: array<int, string>, missing: array<int, string>, rows: array<int, array<int, string>>}
     */
    public function preview(UploadedFile $file, User $user): array
    {
        return $this->previewStored($this->store($file, $user));
    }

    /**
     * The same preview for a file already on disk, so refreshing the preview
     * page (or coming back to it later) re-reads the file instead of relying
     * on anything held in the session.
     *
     * @return array{import: StudentImport, headings: array<int, string>, mapping: array<string, int>, unmapped: array<int, string>, missing: array<int, string>, rows: array<int, array<int, string>>}
     */
    public function previewStored(StudentImport $import): array
    {
        $path = $this->absolutePath($import);

        if (! is_file($path)) {
            return [
                'import' => $import,
                'headings' => [],
                'mapping' => [],
                'unmapped' => [],
                'missing' => (array) config('students.required', ['nim']),
                'rows' => [],
                'sheets' => [],
            ];
        }

        $sheets = $this->sheetsFor($import);

        // Remember the choice so confirm() re-reads the very same rows.
        if ($sheets !== [] && ! in_array((string) $import->sheet, $sheets, true)) {
            $import->forceFill(['sheet' => $this->bestSheet($path, $sheets)])->save();
        }

        $rows = $this->readRows($path, $import->extension, $import->sheet);

        $headings = [];
        $sample = [];
        $limit = (int) config('students.preview_rows', 20);

        foreach ($rows as $index => $row) {
            if ($index === 0) {
                $headings = $row;
                $this->mapper->bindHeadings($headings);

                continue;
            }

            $sample[] = $row;

            if (count($sample) >= $limit) {
                break;
            }
        }

        $mapping = $this->mapper->mapping();

        $import->forceFill(['mapping' => $mapping])->save();

        return [
            'import' => $import,
            'sheets' => $sheets,
            'headings' => $headings,
            'mapping' => $mapping,
            'unmapped' => array_values(array_diff(
                array_map(fn ($h) => trim($h), $headings),
                array_map(fn ($p) => trim($headings[$p] ?? ''), $mapping),
            )),
            'missing' => $this->mapper->missingRequired(),
            'rows' => $sample,
        ];
    }

    /** Queue (or run) the import of an already-uploaded file. */
    public function dispatch(StudentImport $import): void
    {
        // updated_at is set explicitly: it is the clock isStalled() reads, and
        // a re-confirm of an already-pending row would otherwise not touch it.
        $import->forceFill([
            'status' => StudentImport::STATUS_PENDING,
            'progress' => 0,
            'error_message' => null,
            'finished_at' => null,
            'updated_at' => now(),
        ])->save();

        if (config('students.queued', true)) {
            ProcessStudentImport::dispatch($import->id);
        } else {
            $this->import($import);
        }
    }

    /**
     * Runs a stalled import inside the current request, for when the queue
     * worker is not running. Fine for the sizes this app sees (7.000 rows take
     * seconds); the time limit is lifted so a bigger file is not cut off.
     */
    public function runNow(StudentImport $import): void
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $this->import($import);
    }

    /* -----------------------------------------------------------------
     | The import itself
     * ----------------------------------------------------------------- */

    public function import(StudentImport $import): void
    {
        $path = $this->absolutePath($import);

        if (! is_file($path)) {
            $this->fail($import, 'Berkas sumber tidak ditemukan lagi di server.');

            return;
        }

        $import->forceFill([
            'status' => StudentImport::STATUS_PROCESSING,
            'progress' => 0,
            'error_message' => null,
        ])->save();

        try {
            $this->run($import, $path);
        } catch (Throwable $e) {
            report($e);
            $this->fail($import, $e->getMessage());
        }
    }

    private function run(StudentImport $import, string $path): void
    {
        $chunkSize = (int) config('students.chunk', 500);
        $maxRows = (int) config('students.max_rows', 100000);
        $skipExisting = config('students.on_duplicate') === 'skip';

        $total = 0;
        $imported = 0;
        $updated = 0;
        $failed = 0;
        $duplicate = 0;
        $errors = [];

        // NIMs seen earlier in THIS file. A spreadsheet that lists the same
        // student twice must not have the second row overwrite the first
        // silently — it is reported as a duplicate.
        $seen = [];
        $buffer = [];
        $bound = false;

        // Line numbers are what the admin sees in Excel, so they count the
        // header as line 1 and the first data row as line 2.
        foreach ($this->readRows($path, $import->extension, $import->sheet) as $index => $row) {
            if (! $bound) {
                $this->mapper->bindHeadings($row);

                if ($missing = $this->mapper->missingRequired()) {
                    throw new RuntimeException(
                        'Kolom wajib tidak ditemukan di berkas: '.implode(', ', $missing).'.'
                    );
                }

                $import->forceFill(['mapping' => $this->mapper->mapping()])->save();
                $bound = true;

                continue;
            }

            $line = $index + 1;

            // A trailing blank line is not an error.
            if ($this->isBlank($row)) {
                continue;
            }

            $total++;

            if ($total > $maxRows) {
                throw new RuntimeException("Berkas melebihi batas {$maxRows} baris. Pecah menjadi beberapa berkas.");
            }

            $result = $this->mapper->map($row);

            if (! $result['ok']) {
                $failed++;
                $this->recordError($errors, $line, null, $result['reason']);

                continue;
            }

            $data = $this->fitToColumns($result['data']);
            $nim = $data['nim'];

            if ($nim === null) {
                $failed++;
                $this->recordError($errors, $line, null, 'NIM terlalu panjang (maksimal '.$this->columnLimits()['nim'].' karakter)');

                continue;
            }

            if (isset($seen[$nim])) {
                $duplicate++;
                $this->recordError($errors, $line, $nim, 'NIM duplikat di dalam berkas ini (baris '.$seen[$nim].')');

                continue;
            }

            $seen[$nim] = $line;
            $buffer[$nim] = $data;

            if (count($buffer) >= $chunkSize) {
                $this->flush($buffer, $import, $skipExisting, $imported, $updated, $duplicate);
                $buffer = [];
                $this->reportProgress($import, $total);
            }
        }

        if ($buffer !== []) {
            $this->flush($buffer, $import, $skipExisting, $imported, $updated, $duplicate);
        }

        if (! $bound) {
            throw new RuntimeException('Berkas kosong — tidak ada baris header.');
        }

        $import->forceFill([
            'status' => StudentImport::STATUS_COMPLETED,
            'progress' => 100,
            'total_rows' => $total,
            'imported_count' => $imported,
            'updated_count' => $updated,
            'failed_count' => $failed,
            'duplicate_count' => $duplicate,
            'errors' => $errors ?: null,
            'finished_at' => now(),
        ])->save();

        // New kabupaten/pokjar values must be selectable at once, not in an
        // hour when the cache happens to lapse.
        app(StudentStats::class)->forgetOptions();

        $this->log->log(
            'student.imported',
            "Import {$total} baris mahasiswa: {$imported} baru, {$updated} diperbarui, {$failed} gagal, {$duplicate} duplikat",
            $import,
            compact('total', 'imported', 'updated', 'failed', 'duplicate'),
        );
    }

    /**
     * Writes one chunk.
     *
     * Existing rows are updated field by field rather than through a blanket
     * upsert, because the columns an import may touch and the columns it must
     * not (assignment_status, assigned_to, assigned_by, assigned_at,
     * contact_id) are different sets.
     *
     * @param  array<string, array<string, mixed>>  $buffer
     */
    private function flush(
        array $buffer,
        StudentImport $import,
        bool $skipExisting,
        int &$imported,
        int &$updated,
        int &$duplicate,
    ): void {
        $officers = $this->officerIds();

        $existing = Student::withTrashed()
            ->whereIn('nim', array_keys($buffer))
            ->get(['id', 'nim', 'deleted_at'])
            ->keyBy('nim');

        $insert = [];
        $now = now();

        foreach ($buffer as $nim => $data) {
            $data['import_id'] = $import->id;
            $data['extra'] = $data['extra'] === null ? null : json_encode($data['extra']);

            $row = $existing->get($nim);

            if ($row === null) {
                // The officer named in the file, but only if that person
                // already has an account. See the class docblock.
                $officer = $officers[$this->officerKey($data['petugas_nama'] ?? null)] ?? null;

                $insert[] = $data + [
                    'assignment_status' => $officer
                        ? \App\Enums\AssignmentStatus::Assigned->value
                        : \App\Enums\AssignmentStatus::Unassigned->value,
                    'assigned_to' => $officer,
                    'assigned_at' => $officer ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                continue;
            }

            if ($skipExisting) {
                $duplicate++;

                continue;
            }

            // Restore a student who was soft-deleted and has turned up in the
            // list again: their history comes back rather than being orphaned.
            Student::withTrashed()->whereKey($row->id)->update($data + [
                'deleted_at' => null,
                'updated_at' => $now,
            ]);

            $updated++;
        }

        if ($insert !== []) {
            Student::insert($insert);
            $imported += count($insert);
        }
    }

    /* -----------------------------------------------------------------
     | Reading
     * ----------------------------------------------------------------- */

    /** @return Generator<int, array<int, string>> */
    private function readRows(string $path, ?string $extension, ?string $sheet = null): Generator
    {
        return match (strtolower((string) $extension)) {
            'xlsx', 'xls' => (new XlsxReader($path, $sheet))->rows(),
            default => (new CsvReader($path))->rows(),
        };
    }

    /**
     * The worksheets in an upload. Empty for CSV, which has only one.
     *
     * @return array<int, string>
     */
    public function sheetsFor(StudentImport $import): array
    {
        if (! in_array(strtolower((string) $import->extension), ['xlsx', 'xls'], true)) {
            return [];
        }

        $path = $this->absolutePath($import);

        return is_file($path) ? (new XlsxReader($path))->sheetNames() : [];
    }

    /**
     * Which tab actually holds the data.
     *
     * Real workbooks lead with a summary: the supplied file opens on a
     * six-cell DASHBOARD, and the 7.391 student rows sit on the second tab.
     * Taking "the first sheet" would import the summary and call the file
     * empty, so each tab's header row is scored by how many columns it maps
     * and the best one wins. The admin can still override the choice.
     */
    private function bestSheet(string $path, array $sheets): ?string
    {
        $best = null;
        $bestScore = -1;

        foreach ($sheets as $sheet) {
            $headings = [];

            foreach ((new XlsxReader($path, $sheet))->rows() as $row) {
                $headings = $row;
                break;
            }

            if ($headings === []) {
                continue;
            }

            $mapper = clone $this->mapper;
            $mapper->bindHeadings($headings);

            // A tab that lacks the required columns is not a candidate at all,
            // however many other headings it happens to match.
            $score = $mapper->missingRequired() === [] ? count($mapper->mapping()) : -1;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $sheet;
            }
        }

        return $bestScore > 0 ? $best : ($sheets[0] ?? null);
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    private function store(UploadedFile $file, User $user): StudentImport
    {
        $extension = $this->realFormat($file);

        $path = $file->storeAs(
            (string) config('students.directory', 'student-imports'),
            Str::uuid()->toString().'.'.$extension,
            $this->disk(),
        );

        return StudentImport::create([
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'extension' => $extension,
            'status' => StudentImport::STATUS_UPLOADED,
            'created_by' => $user->id,
        ]);
    }

    /**
     * What the file really is, judged by its first bytes rather than its name.
     *
     * The name lies often: an "export to Excel" from a web app is frequently
     * a CSV or tab-separated text saved as .xls, and a real .xlsx sometimes
     * arrives renamed. Reading by content handles both. The one format with
     * no reader here — the old binary .xls of Excel 97-2003 (also how an
     * Excel file with a password looks) — is refused with a fix the admin can
     * act on, instead of failing later with a cryptic error.
     */
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
                .'Buka di Excel, hapus kata sandinya bila ada, lalu Simpan Sebagai "Excel Workbook (.xlsx)" dan unggah ulang.'
            );
        }

        $text = ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $head) ?? $head);

        if ($text === '') {
            throw new RuntimeException('Berkas kosong.');
        }

        if (str_starts_with($text, '<') || str_contains($head, "\0")) {
            throw new RuntimeException(
                'Isi berkas bukan tabel yang bisa dibaca (kemungkinan halaman web atau berkas biner). '
                .'Buka di Excel lalu Simpan Sebagai .xlsx atau .csv.'
            );
        }

        return 'csv';
    }

    /**
     * Cuts each value to its column's length.
     *
     * MySQL in strict mode rejects an over-long value outright, and one long
     * cell would then fail the whole chunk — and with it the import. A
     * truncated note is better than no import. The NIM is the exception: a
     * shortened NIM is a different student, so it comes back null and the
     * row is rejected instead.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fitToColumns(array $data): array
    {
        foreach ($this->columnLimits() as $column => $limit) {
            $value = $data[$column] ?? null;

            if (! is_string($value) || mb_strlen($value) <= $limit) {
                continue;
            }

            $data[$column] = $column === 'nim' ? null : mb_substr($value, 0, $limit);
        }

        return $data;
    }

    /**
     * Length of every string column on `students`, read from the schema so a
     * later migration that widens a column is picked up without a code change.
     *
     * @return array<string, int>
     */
    private function columnLimits(): array
    {
        return $this->limits ??= collect(Schema::getColumns((new Student)->getTable()))
            ->mapWithKeys(fn (array $column) => preg_match('/^(?:var)?char\((\d+)\)/i', (string) $column['type'], $m)
                ? [$column['name'] => (int) $m[1]]
                : [])
            ->all();
    }

    private function absolutePath(StudentImport $import): string
    {
        return Storage::disk($this->disk())->path($import->path);
    }

    private function disk(): string
    {
        return (string) config('students.disk', 'local');
    }

    /**
     * Active users by normalised name, so 7.000 rows cost one query.
     *
     * @return array<string, int>
     */
    private function officerIds(): array
    {
        return $this->officers ??= User::query()
            ->where('is_active', true)
            ->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [$this->officerKey($name) => $id])
            ->all();
    }

    /** Names differ only by spacing and case between systems. */
    private function officerKey(?string $name): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $name))) ?? '';
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

    /**
     * Keeps the error list bounded. The counters stay exact either way, so a
     * truncated list never makes the totals wrong.
     *
     * @param  array<int, array<string, mixed>>  $errors
     */
    private function recordError(array &$errors, int $line, ?string $nim, string $reason): void
    {
        if (count($errors) >= StudentImport::MAX_ERRORS) {
            return;
        }

        $errors[] = array_filter([
            'line' => $line,
            'nim' => $nim,
            'reason' => $reason,
        ], fn ($v) => $v !== null);
    }

    /**
     * Progress without a row count up front: streaming means the total is
     * unknown until the end, so this reports rows processed as a percentage
     * that approaches but never reaches 100 until the import finishes.
     */
    private function reportProgress(StudentImport $import, int $processed): void
    {
        $import->forceFill([
            'total_rows' => $processed,
            'progress' => min(95, (int) floor($processed / max(1, $processed + 500) * 100)),
        ])->saveQuietly();
    }

    private function fail(StudentImport $import, string $message): void
    {
        $import->forceFill([
            'status' => StudentImport::STATUS_FAILED,
            // mb_substr, not Str::limit: limit() APPENDS an ellipsis, so a
            // 1000-char cap produced 1003 characters and the write failed —
            // turning a readable import error into a database exception.
            'error_message' => mb_substr($message, 0, 1000),
            'finished_at' => now(),
        ])->save();

        $this->log->log('student.import_failed', "Import mahasiswa gagal: {$import->original_name}", $import, [
            'error' => Str::limit($message, 300),
        ]);
    }
}
