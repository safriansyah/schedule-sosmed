<?php

namespace App\Jobs;

use App\Models\StudentImport;
use App\Services\Students\StudentImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessStudentImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Long timeout: a large file takes time, but memory stays flat. */
    public int $timeout = 3600;

    /**
     * One attempt only. A retry would re-read the same file from the top and
     * count every already-imported row a second time, so a failed import is
     * fixed and re-run by hand rather than replayed blindly.
     */
    public int $tries = 1;

    public function __construct(public int $importId) {}

    public function handle(StudentImportService $service): void
    {
        $import = StudentImport::find($this->importId);

        // Only a still-pending import. If the admin already ran it by hand
        // because the worker was down, this late job must not import it twice.
        if ($import && $import->status === StudentImport::STATUS_PENDING) {
            $service->import($import);
        }
    }

    public function failed(Throwable $e): void
    {
        StudentImport::where('id', $this->importId)->update([
            'status' => StudentImport::STATUS_FAILED,
            'error_message' => mb_substr($e->getMessage(), 0, 1000),
            'finished_at' => now(),
        ]);
    }
}
