@php
    use App\Enums\TicketStatus;

    $hasFilter = array_filter($filters, fn ($v) => filled($v)) !== [];
@endphp

<x-layouts.app title="Laporan Ticketing">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Laporan Ticketing</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Khusus data tiket. Angka mahasiswa ada di laporannya sendiri.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('reports.index') }}" class="btn-outline">
                <x-icon name="chevron-left" class="h-4 w-4"/> Semua Laporan
            </a>
            @if ($canExport)
                <a href="{{ route('tickets.export', ['format' => 'xlsx'] + array_filter($filters)) }}" class="btn-primary">
                    <x-icon name="download" class="h-4 w-4"/> Export Tiket
                </a>
            @endif
        </div>
    </x-slot:header>

    <form method="GET" class="card mb-6 p-4">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-12">
            <div class="lg:col-span-3">
                <label for="source" class="label">Sumber</label>
                <select id="source" name="source" class="input" onchange="this.form.submit()">
                    <option value="">Semua sumber</option>
                    @foreach ($sources as $sourceGroup => $groupItems)
                        <optgroup label="{{ $sourceGroup }}">
                        @foreach ($groupItems as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['source'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>

            <div class="lg:col-span-3">
                <label for="stage" class="label">Status</label>
                <select id="stage" name="stage" class="input">
                    <option value="">Semua status</option>
                    @foreach ($stages as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['stage'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="lg:col-span-2">
                <label for="from" class="label">Dari</label>
                <input id="from" name="from" type="date" value="{{ $filters['from'] ?? '' }}" class="input">
            </div>

            <div class="lg:col-span-2">
                <label for="to" class="label">Sampai</label>
                <input id="to" name="to" type="date" value="{{ $filters['to'] ?? '' }}" class="input">
            </div>

            <div class="col-span-2 flex items-end gap-2 lg:col-span-2">
                <button class="btn-primary flex-1"><x-icon name="filter" class="h-4 w-4"/></button>
                @if ($hasFilter)
                    <a href="{{ route('reports.tickets') }}" class="btn-outline"><x-icon name="x" class="h-4 w-4"/></a>
                @endif
            </div>
        </div>

        {{-- Region only exists for tickets raised from the student import — an
             Instagram commenter has no address — so these appear only once
             that source is chosen, rather than sitting there returning zero. --}}
        @if ($fromImport)
            <div class="mt-3 border-t border-slate-200 pt-3 dark:border-white/5">
                <p class="label mb-2">Wilayah mahasiswa</p>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    @foreach ($regionLevels as $level)
                        @php $options = $regions[$level] ?? []; @endphp
                        <div>
                            <label for="r-{{ $level }}" class="mb-1 block text-[11px] font-medium text-slate-500 dark:text-slate-400">
                                {{ $regionLabels[$level] }}
                                <span class="text-slate-300 dark:text-slate-600">({{ count($options) }})</span>
                            </label>
                            <select id="r-{{ $level }}" name="{{ $level }}" class="input !py-2 !text-xs"
                                    onchange="this.form.submit()" @disabled(count($options) === 0)>
                                <option value="">{{ count($options) === 0 ? 'Tidak ada data' : 'Semua' }}</option>
                                @foreach ($options as $name)
                                    <option value="{{ $name }}" @selected(($filters[$level] ?? '') === $name)>{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </form>

    {{-- The three headline states the institution asked for. The six internal
         statuses are right for an operator working one ticket and too many for
         a report, so they are the drill-down underneath. --}}
    <div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-4">
        <x-stat-card label="Total tiket" :value="number_format($total)" icon="file-text" tone="brand"/>
        <x-stat-card label="Open" :value="number_format($byStage['open'])" icon="inbox" tone="amber"/>
        <x-stat-card label="Pending" :value="number_format($byStage['pending'])" icon="clock" tone="violet"/>
        <x-stat-card label="Closed" :value="number_format($byStage['closed'])" icon="check-circle" tone="emerald"/>
    </div>

    <div class="mb-6 grid gap-6 lg:grid-cols-2">
        <div class="card overflow-hidden">
            <div class="border-b border-slate-200 p-4 dark:border-white/5">
                <h3 class="text-sm font-bold text-slate-800 dark:text-white">Rincian per status</h3>
            </div>
            <div class="divide-y divide-slate-100 dark:divide-white/5">
                @foreach ($statuses as $value => $label)
                    @php $status = TicketStatus::from($value); @endphp
                    <div class="flex items-center justify-between px-4 py-2.5 text-sm">
                        <span class="flex items-center gap-2">
                            <span class="{{ $status->badge() }}">{{ $label }}</span>
                            <span class="text-[11px] text-slate-400">{{ ucfirst($status->stage()) }}</span>
                        </span>
                        <span class="font-bold text-slate-700 dark:text-slate-200">
                            {{ number_format($byStatus[$value] ?? 0) }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="card overflow-hidden">
            <div class="border-b border-slate-200 p-4 dark:border-white/5">
                <h3 class="text-sm font-bold text-slate-800 dark:text-white">Per sumber</h3>
            </div>
            @if ($bySource->isEmpty())
                <x-empty-state icon="file-text" title="Belum ada tiket" description="Belum ada tiket pada filter ini."/>
            @else
                <div class="divide-y divide-slate-100 dark:divide-white/5">
                    @foreach ($sources as $sourceGroup => $groupItems)
                        <optgroup label="{{ $sourceGroup }}">
                        @foreach ($groupItems as $value => $label)
                        @continue(! $bySource->has($value))
                        <div class="flex items-center justify-between px-4 py-2.5 text-sm">
                            <span class="text-slate-600 dark:text-slate-300">{{ $label }}</span>
                            <span class="font-bold text-slate-700 dark:text-slate-200">{{ number_format($bySource[$value]) }}</span>
                        </div>
                        @endforeach
                        </optgroup>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="mb-6 grid gap-6 lg:grid-cols-2">
        <x-reports.breakdown title="Per kategori" :rows="$byCategory" :total="$total" empty="Belum ada tiket berkategori."/>
        <x-reports.breakdown title="Per operator" :rows="$byOperator" :total="$total" empty="Belum ada tiket ditugaskan."/>
    </div>

    @if ($fromImport)
        <div class="mb-6">
            <x-reports.breakdown title="Per wilayah (kabupaten/kota)" :rows="$byRegion" :total="$total"
                                 empty="Tiket import belum punya data wilayah."/>
        </div>
    @endif

    {{-- Flag is orthogonal to status, so it gets its own row rather than a
         column in the status table: a Closed ticket can still be a Lead, and
         folding the two together would hide exactly the number the
         acquisition side is looking for. --}}
    <div class="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-4">
        <x-stat-card label="Tiket Lead" :value="number_format($flags['lead'])" icon="flame" tone="pink"/>
        <x-stat-card label="Tiket Netral" :value="number_format($flags['netral'])" icon="flag" tone="slate"/>
        <x-stat-card label="Follow up tercatat" :value="number_format($followUps['total'])" icon="phone" tone="violet"/>
        <x-stat-card label="Follow up jatuh tempo" :value="number_format($followUps['due'])" icon="clock" tone="amber"/>
    </div>

    <div class="card mt-6 p-4">
        <p class="flex items-start gap-2 text-xs text-slate-500 dark:text-slate-400">
            <x-icon name="help-circle" class="mt-0.5 h-4 w-4 shrink-0 text-slate-400"/>
            <span>
                Dari {{ number_format($social['comments']) }} komentar masuk,
                {{ number_format($social['with_ticket']) }} sudah jadi tiket dan
                {{ number_format($social['without_ticket']) }} belum.
                Flag Lead sengaja terpisah dari status: tiket yang sudah Closed tetap bisa bertanda Lead,
                dan justru itu angka yang dicari sisi akuisisi.
            </span>
        </p>
    </div>
</x-layouts.app>
