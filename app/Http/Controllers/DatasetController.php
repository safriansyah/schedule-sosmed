<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\ReplaceDatasetRequest;
use App\Http\Requests\StoreDatasetRequest;
use App\Http\Requests\UpdateDatasetRequest;
use App\Models\Dataset;
use App\Repositories\DatasetItemRepository;
use App\Services\ActivityLogger;
use App\Services\Datasets\DatasetAnalyticsService;
use App\Services\Datasets\DatasetImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DatasetController extends Controller
{
    public function __construct(
        private readonly DatasetImportService $importer,
        private readonly DatasetAnalyticsService $analytics,
        private readonly DatasetItemRepository $items,
        private readonly ActivityLogger $logger,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize(Permission::ViewDatasets->value);

        $datasets = Dataset::query()
            ->with('creator:id,name')
            ->when($request->string('q')->isNotEmpty(),
                fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('status'),
                fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return view('datasets.index', compact('datasets'));
    }

    public function store(StoreDatasetRequest $request): RedirectResponse
    {
        $dataset = $this->importer->createFromUpload(
            $request->file('file'),
            $request->user(),
            $request->input('name'),
        );

        return redirect()
            ->route('datasets.show', $dataset)
            ->with('toast', ['type' => 'success', 'message' => "Dataset “{$dataset->name}” is being processed."]);
    }

    public function show(Dataset $dataset): View
    {
        $this->authorize(Permission::ViewDatasets->value);

        $dataset->loadMissing('creator:id,name');

        $analytics = $dataset->isReady() ? $this->analytics->for($dataset) : null;

        return view('datasets.show', compact('dataset', 'analytics'));
    }

    /**
     * Server-side table feed (search / filter / sort / paginate).
     */
    public function table(Request $request, Dataset $dataset): JsonResponse
    {
        $this->authorize(Permission::ViewDatasets->value);

        $paginator = $this->items->paginate($dataset, $request->only([
            'search', 'platform', 'valid', 'qualified',
            'followers_min', 'followers_max', 'bucket',
            'sort', 'dir', 'per_page',
        ]));

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    /**
     * Lightweight poll for the import progress UI.
     */
    public function status(Dataset $dataset): JsonResponse
    {
        $this->authorize(Permission::ViewDatasets->value);

        return response()->json([
            'status' => $dataset->status,
            'progress' => $dataset->progress,
            'total_rows' => $dataset->total_rows,
            'error_message' => $dataset->error_message,
            'ready' => $dataset->isReady(),
        ]);
    }

    public function replace(ReplaceDatasetRequest $request, Dataset $dataset): RedirectResponse
    {
        $this->importer->replaceFromUpload($dataset, $request->file('file'));

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Dataset is being replaced and processed.',
        ]);
    }

    /**
     * Rename a dataset. The slug (route key / public URL) is intentionally
     * kept stable so existing links and bookmarks keep working.
     */
    public function update(UpdateDatasetRequest $request, Dataset $dataset): RedirectResponse
    {
        $old = $dataset->name;
        $dataset->update($request->validated());

        $this->logger->log(
            'dataset.renamed',
            "Renamed dataset “{$old}” → “{$dataset->name}”",
            $dataset,
            ['from' => $old, 'to' => $dataset->name],
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Dataset renamed to “{$dataset->name}”.",
        ]);
    }

    public function destroy(Dataset $dataset): RedirectResponse
    {
        $this->authorize(Permission::ManageDatasets->value);

        $name = $dataset->name;

        if ($dataset->source_path) {
            Storage::disk(config('datasets.source_disk'))->delete($dataset->source_path);
        }

        $dataset->delete(); // dataset_items cascade at the DB level

        $this->logger->log('dataset.deleted', "Deleted dataset “{$name}”", null, ['name' => $name]);

        return redirect()
            ->route('datasets.index')
            ->with('toast', ['type' => 'success', 'message' => "Dataset “{$name}” deleted."]);
    }

    /**
     * Stream-export the (optionally filtered) dataset as CSV or JSON.
     */
    public function export(Request $request, Dataset $dataset): StreamedResponse
    {
        $this->authorize(Permission::ViewDatasets->value);

        $format = $request->string('format')->lower()->value() === 'json' ? 'json' : 'csv';
        $filters = $request->only([
            'search', 'platform', 'valid', 'qualified',
            'followers_min', 'followers_max', 'bucket', 'sort', 'dir',
        ]);

        $rows = $this->items->query($dataset, $filters)->toBase()->lazy(2000);
        $filename = "{$dataset->slug}-export.{$format}";

        $this->logger->log('dataset.exported', "Exported “{$dataset->name}” ({$format})", $dataset);

        if ($format === 'json') {
            return response()->streamDownload(function () use ($rows) {
                echo '[';
                $first = true;
                foreach ($rows as $r) {
                    echo ($first ? '' : ',').json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $first = false;
                }
                echo ']';
            }, $filename, ['Content-Type' => 'application/json']);
        }

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['external_id', 'name', 'username', 'platform', 'followers', 'following', 'posts', 'is_valid', 'is_qualified', 'profile_url', 'reason']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->external_id, $r->name, $r->username, $r->platform,
                    $r->followers, $r->following, $r->posts,
                    $r->is_valid, $r->is_qualified, $r->profile_url, $r->reason,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
