<x-layouts.app title="Data Lead">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Data Lead</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Calon mahasiswa dari iklan, sosprom, mitra, dan UT Pusat. Terbaru di atas.
            </p>
        </div>

        @can(\App\Enums\Permission::ImportLeads->value)
            <a href="{{ route('leads.import.index') }}" class="btn-primary">
                <x-icon name="upload" class="h-4 w-4"/> Import Data Lead
            </a>
        @endcan
    </x-slot:header>

    @php
        $tones = ['meta_ads' => 'sky', 'absensi_sosprom' => 'amber', 'tiktok_ads' => 'pink', 'mitra' => 'violet', 'ut_pusat' => 'emerald'];
    @endphp

    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 xl:grid-cols-6">
        <x-stat-card label="Total lead" :value="number_format($total)" icon="target" tone="brand"
                     :href="route('leads.index')"/>
        @foreach ($sources as $value => $label)
            <x-stat-card :label="$label" :value="number_format($bySource[$value] ?? 0)" icon="users" :tone="$tones[$value] ?? 'slate'"
                         :href="route('leads.index', ['sumber' => $value])"/>
        @endforeach
    </div>

    <form method="GET" class="card mb-6 p-4">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-12 lg:items-end">
            <div class="col-span-2 lg:col-span-5">
                <label for="q" class="label">Cari nama, No HP, kota, atau sekolah</label>
                <div class="relative">
                    <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"/>
                    <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="input pl-9" placeholder="Kata kunci…">
                </div>
            </div>

            <div class="lg:col-span-3">
                <label for="sumber" class="label">Sumber data</label>
                <select id="sumber" name="sumber" class="input">
                    <option value="">Semua sumber</option>
                    @foreach ($sources as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['sumber'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="lg:col-span-2">
                <label for="kota" class="label">Kota / Kabupaten</label>
                <select id="kota" name="kota" class="input">
                    <option value="">Semua</option>
                    @foreach ($kota as $name)
                        <option value="{{ $name }}" @selected(($filters['kota'] ?? '') === $name)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-span-2 flex gap-2 lg:col-span-2">
                <button class="btn-primary flex-1"><x-icon name="filter" class="h-4 w-4"/> Filter</button>
                @if (array_filter($filters))
                    <a href="{{ route('leads.index') }}" class="btn-outline" aria-label="Reset filter"><x-icon name="x" class="h-4 w-4"/></a>
                @endif
            </div>
        </div>
    </form>

    <div class="card overflow-hidden">
        @if ($leads->isEmpty())
            <x-empty-state icon="target" title="Belum ada data lead"
                           :description="array_filter($filters) ? 'Tidak ada lead yang cocok dengan filter.' : 'Import daftar lead dari Excel atau CSV untuk mulai.'"/>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-white/5 dark:bg-white/[0.02]">
                        <tr>
                            <th class="px-4 py-3 font-semibold">Nama</th>
                            <th class="px-4 py-3 font-semibold">No HP</th>
                            <th class="px-4 py-3 font-semibold">Kota / Kabupaten</th>
                            <th class="hidden px-4 py-3 font-semibold md:table-cell">Asal Sekolah</th>
                            <th class="px-4 py-3 font-semibold">Sumber</th>
                            <th class="hidden px-4 py-3 font-semibold lg:table-cell">Masuk</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                        @foreach ($leads as $lead)
                            <tr class="transition hover:bg-slate-50/60 dark:hover:bg-white/[0.02]">
                                <td class="px-4 py-3 font-semibold text-slate-700 dark:text-slate-200">{{ $lead->nama }}</td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <a href="{{ \App\Support\PhoneNumber::waLink($lead->no_hp) }}" target="_blank" rel="noopener"
                                       class="inline-flex items-center gap-1.5 font-mono text-xs text-emerald-600 hover:underline dark:text-emerald-400">
                                        <x-icon name="whatsapp" class="h-3.5 w-3.5"/> {{ \App\Support\PhoneNumber::pretty($lead->no_hp) }}
                                    </a>
                                </td>
                                <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ $lead->kota ?? '—' }}</td>
                                <td class="hidden max-w-[16rem] truncate px-4 py-3 text-slate-500 md:table-cell dark:text-slate-400">{{ $lead->asal_sekolah ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3"><span class="{{ $lead->sumber->badge() }}">{{ $lead->sumber->label() }}</span></td>
                                <td class="hidden whitespace-nowrap px-4 py-3 text-xs text-slate-400 lg:table-cell">{{ $lead->created_at->translatedFormat('d M Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-100 p-4 dark:border-white/5">{{ $leads->links() }}</div>
        @endif
    </div>
</x-layouts.app>
