<?php

namespace App\Http\Controllers;

use App\Enums\LeadSource;
use App\Enums\Permission;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Data Lead: the imported prospects, newest first. */
class LeadController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize(Permission::ViewLeads->value);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'sumber' => ['nullable', Rule::enum(LeadSource::class)],
            'kota' => ['nullable', 'string', 'max:255'],
        ]);

        $leads = Lead::query()
            ->search($filters['q'] ?? null)
            ->when($filters['sumber'] ?? null, fn ($q, $s) => $q->where('sumber', $s))
            ->when($filters['kota'] ?? null, fn ($q, $k) => $q->where('kota', $k))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $bySource = Lead::query()->selectRaw('sumber, count(*) as total')->groupBy('sumber')->pluck('total', 'sumber');

        return view('leads.index', [
            'leads' => $leads,
            'filters' => $filters,
            'sources' => LeadSource::options(),
            'kota' => Lead::query()->whereNotNull('kota')->distinct()->orderBy('kota')->pluck('kota'),
            'total' => (int) $bySource->sum(),
            'bySource' => $bySource,
        ]);
    }
}
