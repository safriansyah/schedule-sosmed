<?php

namespace App\Services\Datasets;

use App\Jobs\ProcessDatasetImport;
use App\Models\User;
use App\Models\Dataset;
use App\Models\DatasetItem;
use App\Services\ActivityLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DatasetImportService
{
    public function __construct(
        private readonly JsonDatasetReader $reader,
        private readonly JsonItemNormalizer $normalizer,
        private readonly ActivityLogger $logger,
    ) {
    }

    /**
     * Persist the upload and create a pending dataset, then kick off import.
     */
    public function createFromUpload(UploadedFile $file, User $user, ?string $name = null): Dataset
    {
        $display = $this->resolveName($name, $file->getClientOriginalName());

        $dataset = new Dataset([
            'name' => $display,
            'slug' => $this->uniqueSlug($display),
            'source_filename' => $file->getClientOriginalName(),
            'source_path' => $this->storeSource($file),
            'status' => Dataset::STATUS_PENDING,
            'created_by' => $user->id,
        ]);
        $dataset->save();

        $this->logger->log('dataset.created', "Created dataset “{$dataset->name}”", $dataset, [
            'source' => $dataset->source_filename,
        ]);

        $this->dispatchImport($dataset);

        return $dataset;
    }

    /**
     * Replace a dataset's data with a freshly uploaded file.
     */
    public function replaceFromUpload(Dataset $dataset, UploadedFile $file): Dataset
    {
        if ($dataset->source_path && Storage::disk($this->disk())->exists($dataset->source_path)) {
            Storage::disk($this->disk())->delete($dataset->source_path);
        }

        $dataset->forceFill([
            'source_filename' => $file->getClientOriginalName(),
            'source_path' => $this->storeSource($file),
            'status' => Dataset::STATUS_PENDING,
            'progress' => 0,
            'error_message' => null,
        ])->save();

        $this->logger->log('dataset.replaced', "Replaced data for “{$dataset->name}”", $dataset);

        $this->dispatchImport($dataset);

        return $dataset;
    }

    /**
     * The heavy lifting: stream → normalise → chunked bulk insert.
     * Runs inside the queued job (or synchronously when queue is off).
     */
    public function import(Dataset $dataset): void
    {
        $path = Storage::disk($this->disk())->path($dataset->source_path);

        if (! is_file($path)) {
            $this->fail($dataset, 'Source file is missing on disk.');

            return;
        }

        $dataset->forceFill([
            'status' => Dataset::STATUS_PROCESSING,
            'progress' => 0,
            'error_message' => null,
        ])->save();

        try {
            $meta = $this->reader->metadata($path);
            $total = (int) ($meta['total_checked'] ?? $meta['total_input'] ?? $meta['total'] ?? 0);
            if ($total <= 0) {
                $total = $this->reader->count($path);
            }

            // Wipe previous rows (re-import / replace) without exhausting memory.
            DatasetItem::where('dataset_id', $dataset->id)->delete();

            $chunkSize = (int) config('datasets.import_chunk', 1000);
            $now = now()->toDateTimeString();

            $buffer = [];
            $processed = 0;
            $valid = 0;
            $invalid = 0;
            $qualified = 0;
            $lastProgress = 0;

            foreach ($this->reader->rows($path) as $row) {
                $item = $this->normalizer->normalize($row, $dataset->id, $now);

                $item['is_valid'] ? $valid++ : $invalid++;
                if ($item['is_qualified']) {
                    $qualified++;
                }

                $buffer[] = $item;
                $processed++;

                if (count($buffer) >= $chunkSize) {
                    DatasetItem::insert($buffer);
                    $buffer = [];

                    if ($total > 0) {
                        $pct = (int) min(99, floor($processed / $total * 100));
                        if ($pct >= $lastProgress + 2) {
                            $dataset->forceFill(['progress' => $pct])->saveQuietly();
                            $lastProgress = $pct;
                        }
                    }
                }
            }

            if ($buffer) {
                DatasetItem::insert($buffer);
            }

            $dataset->forceFill([
                'status' => Dataset::STATUS_COMPLETED,
                'progress' => 100,
                'total_rows' => $processed,
                'valid_count' => $valid,
                'invalid_count' => $invalid,
                'qualified_count' => $qualified,
                'imported_at' => now(),
                'meta' => array_merge($dataset->meta ?? [], ['source' => $meta]),
                'error_message' => null,
            ])->save(); // save() bumps updated_at → busts analytics cache

            $this->logger->log('dataset.imported', "Imported {$processed} rows into “{$dataset->name}”", $dataset, [
                'rows' => $processed,
                'valid' => $valid,
                'qualified' => $qualified,
            ]);
        } catch (Throwable $e) {
            report($e);
            $this->fail($dataset, $e->getMessage());
        }
    }

    private function fail(Dataset $dataset, string $message): void
    {
        $dataset->forceFill([
            'status' => Dataset::STATUS_FAILED,
            'error_message' => Str::limit($message, 1000),
        ])->save();

        $this->logger->log('dataset.failed', "Import failed for “{$dataset->name}”", $dataset, [
            'error' => Str::limit($message, 300),
        ]);
    }

    private function dispatchImport(Dataset $dataset): void
    {
        if (config('datasets.queued', true)) {
            ProcessDatasetImport::dispatch($dataset->id);
        } else {
            $this->import($dataset);
        }
    }

    private function storeSource(UploadedFile $file): string
    {
        $name = Str::uuid()->toString().'.json';

        return $file->storeAs(
            config('datasets.source_dir', 'dataset-sources'),
            $name,
            $this->disk()
        );
    }

    private function resolveName(?string $name, string $original): string
    {
        $name = trim((string) $name);

        if ($name !== '') {
            return Str::limit($name, 120, '');
        }

        // "+5000-hasil-ut-pkp.json"  ->  "5000-hasil-ut-pkp"
        $base = Str::of($original)->beforeLast('.')->slug();

        return $base->isEmpty() ? 'dataset-'.now()->format('Ymd-His') : (string) $base;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'dataset';
        $slug = $base;
        $i = 2;

        while (Dataset::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    private function disk(): string
    {
        return config('datasets.source_disk', 'local');
    }
}
