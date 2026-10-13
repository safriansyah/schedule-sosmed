<?php

namespace App\Http\Controllers;

use App\Enums\LeadSource;
use App\Enums\Permission;
use App\Models\Lead;
use App\Services\ActivityLogger;
use App\Services\Export\Exporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Data Lead: the imported prospects, newest first. */
class LeadController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize(Permission::ViewLeads->value);

        [$query, $filters] = $this->filtered($request);

        $bySource = Lead::query()->selectRaw('sumber, count(*) as total')->groupBy('sumber')->pluck('total', 'sumber');

        return view('leads.index', [
            'leads' => $query->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
            'sources' => LeadSource::options(),
            'kota' => Lead::query()->whereNotNull('kota')->distinct()->orderBy('kota')->pluck('kota'),
            'total' => (int) $bySource->sum(),
            'bySource' => $bySource,
        ]);
    }

    /** Download what the list shows — same search and filters, newest first. */
    public function export(Request $request, Exporter $exporter, ActivityLogger $log)
    {
        $this->authorize(Permission::ExportLeads->value);

        [$query, $filters] = $this->filtered($request);

        $log->log('lead.exported', 'Export data lead', null, ['filters' => array_filter($filters)]);

        $source = isset($filters['sumber']) ? LeadSource::from($filters['sumber']) : null;

        return $exporter->download(
            format: 'xlsx',
            filename: 'data-lead'.($source ? '-'.str_replace('_', '-', $source->value) : ''),
            columns: [
                'nama' => 'Nama',
                'no_hp' => 'No HP',
                'kota' => 'Kota / Kabupaten',
                'asal_sekolah' => 'Asal Sekolah',
                'sumber' => 'Sumber Data',
                'masuk' => 'Tanggal Masuk',
                'berkas' => 'Berkas Import',
                'extra' => 'Data Tambahan',
            ],
            query: $query->with('import:id,original_name'),
            mapper: fn (Lead $lead) => [
                $lead->nama,
                // 0812…, kept as text so Excel does not drop the leading 0.
                '0'.substr($lead->no_hp, 2),
                $lead->kota,
                $lead->asal_sekolah,
                $lead->sumber->label(),
                $lead->created_at->timezone('Asia/Jakarta')->format('d/m/Y H:i'),
                $lead->import?->original_name,
                collect($lead->extra ?? [])->map(fn ($v, $k) => "{$k}: {$v}")->implode('; '),
            ],
        );
    }

    /** @return array{0: Builder, 1: array<string, mixed>} */
    private function filtered(Request $request): array
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'sumber' => ['nullable', Rule::enum(LeadSource::class)],
            'kota' => ['nullable', 'string', 'max:255'],
        ]);

        $query = Lead::query()
            ->search($filters['q'] ?? null)
            ->when($filters['sumber'] ?? null, fn ($q, $s) => $q->where('sumber', $s))
            ->when($filters['kota'] ?? null, fn ($q, $k) => $q->where('kota', $k));

        return [$query, $filters];
    }
}
