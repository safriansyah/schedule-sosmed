<?php

namespace App\Jobs;

use App\Models\Dataset;
use App\Services\Datasets\DatasetImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessDatasetImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Long timeout — large files take time, but parsing is memory-constant. */
    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $datasetId)
    {
    }

    public function handle(DatasetImportService $service): void
    {
        $dataset = Dataset::find($this->datasetId);

        if ($dataset) {
            $service->import($dataset);
        }
    }

    public function failed(Throwable $e): void
    {
        Dataset::where('id', $this->datasetId)->update([
            'status' => Dataset::STATUS_FAILED,
            'error_message' => mb_substr($e->getMessage(), 0, 1000),
        ]);
    }
}
