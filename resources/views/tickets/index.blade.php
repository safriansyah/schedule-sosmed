<x-layouts.app :title="$mine ? 'Tiket Saya' : 'Semua Tiket'">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">
                {{ $mine ? 'Tiket Saya' : 'Semua Tiket' }}
            </h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Kasus yang membutuhkan tindak lanjut. Follow up dilakukan di dalam detail tiket.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            @can(\App\Enums\Permission::ManageTicketCategories->value)
                <a href="{{ route('tickets.categories.index') }}" class="btn-outline">
                    <x-icon name="hash" class="h-4 w-4"/> Kategori
                </a>
            @endcan

            @can(\App\Enums\Permission::CreateTickets->value)
                <a href="{{ route('tickets.create') }}" class="btn-primary">
                    <x-icon name="plus" class="h-4 w-4"/> Tiket Baru
                </a>
            @endcan
        </div>
    </x-slot:header>

    <div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-6">
        <x-stat-card label="Total tiket" :value="number_format($stats['total'])" icon="inbox" tone="brand"/>
        <x-stat-card label="Open" :value="number_format($stats['open'])" icon="sparkles" tone="amber"/>
        <x-stat-card label="Dikerjakan" :value="number_format($stats['assigned'] + $stats['in_progress'])" icon="refresh" tone="cyan"/>
        <x-stat-card label="Follow up" :value="number_format($stats['follow_up'])" icon="phone" tone="violet"/>
        <x-stat-card label="Selesai" :value="number_format($stats['resolved'])" icon="check-circle" tone="emerald"/>
        <x-stat-card label="Ditutup" :value="number_format($stats['closed'])" icon="ban" tone="slate"/>
    </div>

    <form method="GET" class="card mb-6 p-4">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-12">
            <div class="col-span-2 lg:col-span-3">
                <label for="q" class="label">Cari</label>
                <div class="relative">
                    <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"/>
                    <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="input pl-9" placeholder="Nomor, judul, nama, NIM…">
                </div>
            </div>

            <div class="lg:col-span-2">
                <label for="status" class="label">Status</label>
                <select id="status" name="status" class="input">
                    <option value="">Semua status</option>
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="lg:col-span-2">
                <label for="source" class="label">Sumber</label>
                {{-- Submits on change so the region selects below can appear
                     (or disappear) the moment Import Mahasiswa is chosen. --}}
                <select id="source" name="source" class="input" onchange="this.form.submit()">
                    <option value="">Semua sumber</option>
                    @foreach ($sources as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['source'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-span-2 lg:col-span-2">
                <label for="category" class="label">Kategori</label>
                <select id="category" name="category" class="input">
                    <option value="">Semua kategori</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) ($filters['category'] ?? '') === (string) $category->id)>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="lg:col-span-1">
                <label for="flag" class="label">Flag</label>
                <select id="flag" name="flag" class="input">
                    <option value="">Semua</option>
                    @foreach ($flags as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['flag'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="lg:col-span-1">
                <label for="priority" class="label">Prioritas</label>
                <select id="priority" name="priority" class="input">
                    <option value="">Semua</option>
                    @foreach ($priorities as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['priority'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-span-2 flex items-end gap-2 lg:col-span-1">
                <button class="btn-primary w-full"><x-icon name="filter" class="h-4 w-4"/></button>
                @if (array_filter($filters))
                    <a href="{{ route($mine ? 'tickets.mine' : 'tickets.index') }}" class="btn-outline">
                        <x-icon name="x" class="h-4 w-4"/>
                    </a>
                @endif
            </div>
        </div>

        {{-- Region, only for tickets raised from the student import. Every
             other source has a commenter, not an address, so offering these
             there would be a filter that always returns nothing. --}}
        @if ($showRegion)
            <div class="mt-3 border-t border-slate-200 pt-3 dark:border-white/5">
                <p class="label mb-2">Wilayah mahasiswa</p>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    @foreach ($regionLevels as $level)
                        @php $options = $regions[$level] ?? []; @endphp
                        <div>
                            <label for="region-{{ $level }}" class="mb-1 block text-[11px] font-medium text-slate-500 dark:text-slate-400">
                                {{ $regionLabels[$level] }}
                                <span class="text-slate-300 dark:text-slate-600">({{ count($options) }})</span>
                            </label>

                            <select id="region-{{ $level }}" name="{{ $level }}" class="input !py-2 !text-xs"
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

    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-bold text-slate-800 dark:text-white">
            {{ number_format($tickets->total()) }} tiket
        </h2>

        @can(\App\Enums\Permission::ExportData->value)
            <div class="flex gap-2">
                @foreach (['xlsx' => 'Excel', 'csv' => 'CSV', 'json' => 'JSON'] as $format => $label)
                    <a href="{{ route('tickets.export', array_merge(request()->query(), ['format' => $format])) }}"
                       class="btn-outline btn-sm">
                        <x-icon name="download" class="h-3.5 w-3.5"/> {{ $label }}
                    </a>
                @endforeach
            </div>
        @endcan
    </div>

    @if ($tickets->isEmpty())
        <div class="card">
            <x-empty-state icon="inbox" title="Belum ada tiket"
                           description="Tiket dibuat dari komentar Instagram, dari data mahasiswa, atau secara manual.">
                <x-slot:action>
                    @can(\App\Enums\Permission::CreateTickets->value)
                        <a href="{{ route('tickets.create') }}" class="btn-primary">
                            <x-icon name="plus" class="h-4 w-4"/> Tiket Baru
                        </a>
                    @endcan
                </x-slot:action>
            </x-empty-state>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($tickets as $ticket)
                <a href="{{ route('tickets.show', $ticket) }}" class="card-glow block p-4">
                    <div class="flex flex-wrap items-start gap-3">
                        <span class="shrink-0 font-mono text-xs font-bold text-brand-600 dark:text-brand-400">
                            {{ $ticket->number }}
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold text-slate-800 dark:text-white">{{ $ticket->subject }}</p>

                            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-400">
                                <span class="{{ $ticket->source->badge() }}">
                                    <x-icon :name="$ticket->source->icon()" class="h-3 w-3"/>
                                    {{ $ticket->source->label() }}
                                </span>

                                <span>{{ $ticket->requesterLabel() }}</span>

                                @if ($ticket->category)
                                    <span>· {{ $ticket->category->name }}@if ($ticket->subCategory) / {{ $ticket->subCategory->name }}@endif</span>
                                @endif

                                @if ($ticket->follow_up_count > 0)
                                    <span>· {{ $ticket->follow_up_count }} follow up</span>
                                @endif

                                <span>· {{ $ticket->created_at->diffForHumans() }}</span>
                            </div>
                        </div>

                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            @if ($ticket->isLead())
                                <span class="{{ $ticket->flag->badge() }}">
                                    <x-icon :name="$ticket->flag->icon()" class="h-3 w-3"/> Lead
                                </span>
                            @endif

                            @if ($ticket->priority !== \App\Enums\Priority::Normal)
                                <span class="{{ $ticket->priority->badge() }}">
                                    <x-icon :name="$ticket->priority->icon()" class="h-3 w-3"/>
                                    {{ $ticket->priority->label() }}
                                </span>
                            @endif

                            <span class="{{ $ticket->status->badge() }}">
                                <x-icon :name="$ticket->status->icon()" class="h-3 w-3"/>
                                {{ $ticket->status->label() }}
                            </span>

                            <span class="text-xs text-slate-400">
                                {{ $ticket->assignee?->name ?? 'Belum ditugaskan' }}
                            </span>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>

        <div class="mt-6">{{ $tickets->links() }}</div>
    @endif
</x-layouts.app>
