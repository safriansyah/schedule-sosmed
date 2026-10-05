<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\DatasetItemRequest;
use App\Models\Dataset;
use App\Models\DatasetItem;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DatasetItemController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger)
    {
    }

    public function store(DatasetItemRequest $request, Dataset $dataset): JsonResponse
    {
        $item = $dataset->items()->create($request->validated());

        $this->recount($dataset);
        $this->logger->log('item.created', "Menambah baris di “{$dataset->name}”", $dataset, ['item_id' => $item->id]);

        return response()->json(['ok' => true, 'item' => $item], 201);
    }

    public function update(DatasetItemRequest $request, Dataset $dataset, DatasetItem $item): JsonResponse
    {
        abort_unless($item->dataset_id === $dataset->id, 404);

        $item->update($request->validated());

        $this->recount($dataset);
        $this->logger->log('item.updated', "Mengubah baris di “{$dataset->name}”", $dataset, ['item_id' => $item->id]);

        return response()->json(['ok' => true, 'item' => $item->fresh()]);
    }

    public function destroy(Dataset $dataset, DatasetItem $item): JsonResponse
    {
        $this->authorize(Permission::ManageDatasets->value);

        abort_unless($item->dataset_id === $dataset->id, 404);

        $item->delete();

        $this->recount($dataset);
        $this->logger->log('item.deleted', "Menghapus baris dari “{$dataset->name}”", $dataset, ['item_id' => $item->id]);

        return response()->json(['ok' => true]);
    }

    public function bulkDestroy(Request $request, Dataset $dataset): JsonResponse
    {
        $this->authorize(Permission::ManageDatasets->value);

        $ids = collect($request->input('ids', []))
            ->map(fn ($v) => (int) $v)->filter()->take(5000)->all();

        if (empty($ids)) {
            return response()->json(['ok' => false, 'message' => 'Tidak ada baris yang dipilih.'], 422);
        }

        $deleted = DatasetItem::where('dataset_id', $dataset->id)
            ->whereIn('id', $ids)->delete();

        $this->recount($dataset);
        $this->logger->log('item.bulk_deleted', "Menghapus {$deleted} baris sekaligus dari “{$dataset->name}”", $dataset, [
            'count' => $deleted,
        ]);

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }

    /**
     * Refresh denormalised counters and bust the analytics cache (the
     * dataset's updated_at change invalidates the signature-keyed cache).
     */
    private function recount(Dataset $dataset): void
    {
        $agg = DB::table('dataset_items')
            ->where('dataset_id', $dataset->id)
            ->selectRaw('
                COUNT(*) total,
                COALESCE(SUM(is_valid), 0) valid,
                COALESCE(SUM(is_valid = 0), 0) invalid,
                COALESCE(SUM(is_qualified), 0) qualified
            ')->first();

        $dataset->forceFill([
            'total_rows' => (int) $agg->total,
            'valid_count' => (int) $agg->valid,
            'invalid_count' => (int) $agg->invalid,
            'qualified_count' => (int) $agg->qualified,
        ])->save();
    }
}
