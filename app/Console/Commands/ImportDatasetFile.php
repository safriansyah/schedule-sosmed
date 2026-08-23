<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Datasets\DatasetImportService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/**
 * Import a JSON dataset straight from the filesystem — handy for seeding real
 * data, migrating from another install, or re-running a failed import without
 * going through the browser upload.
 */
class ImportDatasetFile extends Command
{
    protected $signature = 'datasets:import
                            {path : Path to the JSON file}
                            {--name= : Display name (defaults to the file name)}
                            {--user= : Email of the owner (defaults to the first Super Admin)}';

    protected $description = 'Impor file JSON dataset dari filesystem.';

    public function handle(DatasetImportService $importer): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("File tidak ditemukan: {$path}");

            return self::FAILURE;
        }

        $user = $this->resolveUser();

        if (! $user) {
            $this->error('Tidak ada pengguna yang bisa dijadikan pemilik dataset.');

            return self::FAILURE;
        }

        // Run the parse inline so the command reports the real outcome.
        config(['datasets.queued' => false]);

        $this->info('Mengimpor '.basename($path).' …');

        $dataset = $importer->createFromUpload(
            new UploadedFile($path, basename($path), 'application/json', null, true),
            $user,
            $this->option('name'),
        );

        $dataset->refresh();

        if ($dataset->status === $dataset::STATUS_FAILED) {
            $this->error("Gagal: {$dataset->error_message}");

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Selesai — "%s": %s baris, %s valid, %s qualified.',
            $dataset->name,
            number_format($dataset->total_rows),
            number_format($dataset->valid_count),
            number_format($dataset->qualified_count),
        ));

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        if ($email = $this->option('user')) {
            return User::where('email', $email)->first();
        }

        return User::whereHas('role', fn ($q) => $q->where('name', 'super_admin'))->first()
            ?? User::first();
    }
}
