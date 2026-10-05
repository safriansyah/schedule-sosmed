<x-layouts.app title="Data Mahasiswa">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Data Mahasiswa</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Mahasiswa semester terakhir yang terindikasi tidak melanjutkan.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            @can(\App\Enums\Permission::AssignStudents->value)
                <a href="{{ route('students.unsigned') }}" class="btn-outline">
                    <x-icon name="inbox" class="h-4 w-4"/> Unsigned &amp; Ticket
                </a>
            @endcan

            @can(\App\Enums\Permission::ImportStudents->value)
                <a href="{{ route('students.import.index') }}" class="btn-primary">
                    <x-icon name="upload" class="h-4 w-4"/> Import Data
                </a>
            @endcan
        </div>
    </x-slot:header>

    <div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-5">
        <x-stat-card label="Total mahasiswa" :value="number_format($stats['total'])" icon="users" tone="brand"/>
        <x-stat-card label="Belum assigned" :value="number_format($stats['unassigned'])" icon="inbox" tone="slate"
                     :href="$canAssign ? route('students.unsigned') : null"/>
        <x-stat-card label="Sudah assigned" :value="number_format($stats['assigned'])" icon="user-plus" tone="cyan"/>
        <x-stat-card label="Sedang follow up" :value="number_format($stats['follow_up'])" icon="phone" tone="violet"/>
        <x-stat-card label="Selesai" :value="number_format($stats['resolved'] + $stats['closed'])" icon="check-circle" tone="emerald"/>
    </div>

    {{-- Filters. Kecamatan narrows to the chosen kabupaten via a small fetch,
         so a province with 200 kecamatan does not ship them all every page. --}}
    <form method="GET" class="card mb-6 p-4"
          x-data="kecamatanPicker(@js(route('students.kecamatan')), @js($filters['kecamatan'] ?? ''))">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-12">
            <div class="col-span-2 lg:col-span-3">
                <label for="q" class="label">Cari NIM, NAC, nama, atau HP</label>
                <div class="relative">
                    <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"/>
                    <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="input pl-9" placeholder="Kata kunci…">
                </div>
            </div>

            <div class="lg:col-span-2">
                <label for="kabupaten" class="label">Kabupaten/Kota</label>
                <select id="kabupaten" name="kabupaten" class="input" x-model="kabupaten" @change="load()">
                    <option value="">Semua</option>
                    @foreach ($kabupaten as $name)
                        <option value="{{ $name }}" @selected(($filters['kabupaten'] ?? '') === $name)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="lg:col-span-2">
                <label for="kecamatan" class="label">Kecamatan</label>
                <select id="kecamatan" name="kecamatan" class="input" x-model="kecamatan">
                    <option value="">Semua</option>
                    <template x-for="name in options" :key="name">
                        <option :value="name" x-text="name"></option>
                    </template>
                </select>
            </div>

            <div class="col-span-2 lg:col-span-3">
                <label for="import" class="label">Asal data</label>
                <select id="import" name="import" class="input">
                    <option value="">Semua import</option>
                    @foreach ($imports as $id => $label)
                        <option value="{{ $id }}" @selected((string) ($filters['import'] ?? '') === (string) $id)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="lg:col-span-2">
                <label for="kondisi" class="label">Kondisi</label>
                <select id="kondisi" name="kondisi" class="input">
                    <option value="">Semua kondisi</option>
                    @foreach ($conditions as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['kondisi'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="lg:col-span-2">
                <label for="segmen" class="label">Segmen</label>
                <select id="segmen" name="segmen" class="input">
                    <option value="">Semua segmen</option>
                    @foreach ($segmenOptions as $name)
                        <option value="{{ $name }}" @selected(($filters['segmen'] ?? '') === $name)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="lg:col-span-2">
                <label for="pokjar" class="label">Pokjar / SALUT</label>
                <select id="pokjar" name="pokjar" class="input">
                    <option value="">Semua pokjar</option>
                    @foreach ($pokjarOptions as $name)
                        <option value="{{ $name }}" @selected(($filters['pokjar'] ?? '') === $name)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="lg:col-span-2">
                <label for="assignment" class="label">Assignment</label>
                <select id="assignment" name="assignment" class="input">
                    <option value="">Semua status</option>
                    @foreach ($assignments as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['assignment'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-span-2 flex items-end gap-2 lg:col-span-1">
                <button class="btn-primary w-full"><x-icon name="filter" class="h-4 w-4"/></button>
                @if (array_filter($filters))
                    <a href="{{ route('students.index') }}" class="btn-outline"><x-icon name="x" class="h-4 w-4"/></a>
                @endif
            </div>
        </div>

        @can(\App\Enums\Permission::ViewAllStudents->value)
            <div class="mt-3 grid gap-3 lg:grid-cols-4">
                <div>
                    <label for="operator" class="label">Operator</label>
                    <select id="operator" name="operator" class="input">
                        <option value="">Semua operator</option>
                        <option value="0" @selected(($filters['operator'] ?? '') === '0')>— Belum assigned —</option>
                        <x-operator-options :operators="$operators" :selected="($filters['operator'] ?? '') === '0' ? null : ($filters['operator'] ?? null)"/>
                    </select>
                </div>
            </div>
        @endcan
    </form>

    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-4">
            <h2 class="text-base font-bold text-slate-800 dark:text-white">
                {{ number_format($students->total()) }} mahasiswa
            </h2>

            <x-per-page :options="$perPageOptions" :current="$perPage"/>
        </div>

        @can(\App\Enums\Permission::ExportData->value)
            <div class="flex gap-2">
                @foreach (['xlsx' => 'Excel', 'csv' => 'CSV', 'json' => 'JSON'] as $format => $label)
                    <a href="{{ route('students.export', array_merge(request()->query(), ['format' => $format])) }}"
                       class="btn-outline btn-sm">
                        <x-icon name="download" class="h-3.5 w-3.5"/> {{ $label }}
                    </a>
                @endforeach
            </div>
        @endcan
    </div>

    @if ($students->isEmpty())
        <div class="card">
            <x-empty-state icon="users" title="Belum ada data mahasiswa"
                           description="Import berkas Excel/CSV untuk mengisi daftar ini.">
                <x-slot:action>
                    @can(\App\Enums\Permission::ImportStudents->value)
                        <a href="{{ route('students.import.index') }}" class="btn-primary">
                            <x-icon name="upload" class="h-4 w-4"/> Import Data
                        </a>
                    @endcan
                </x-slot:action>
            </x-empty-state>
        </div>
    @else
        {{-- Phone: one card per student. A six-column table in a horizontal
             scroller is technically "responsive" and practically unusable, so
             the same fields are stacked instead. --}}
        <div class="space-y-3 md:hidden">
            @foreach ($students as $student)
                <a href="{{ route('students.show', $student) }}" class="card-glow block p-4">
                    <div class="flex items-start gap-3">
                        <span class="avatar h-10 w-10 shrink-0 text-xs">{{ $student->initial() }}</span>

                        <div class="min-w-0 flex-1">
                            <p class="truncate font-bold text-slate-800 dark:text-white">{{ $student->name() }}</p>
                            <p class="font-mono text-[11px] text-slate-400">{{ $student->nim }}</p>
                        </div>

                        <span class="{{ $student->assignment_status->badge() }} shrink-0">
                            {{ $student->assignment_status->label() }}
                        </span>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-1.5">
                        <span class="{{ $student->kategori_masalah->badge() }}">
                            {{ $student->kategori_masalah->short() }}
                        </span>
                        @if ($student->status_dp)
                            <span class="badge-slate">{{ $student->status_dp }}</span>
                        @endif
                    </div>

                    <dl class="mt-3 space-y-1 border-t border-slate-100 pt-3 text-xs dark:border-white/5">
                        <div class="flex justify-between gap-3">
                            <dt class="shrink-0 text-slate-400">Wilayah</dt>
                            <dd class="truncate text-right text-slate-600 dark:text-slate-300">{{ $student->regionLabel() }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="shrink-0 text-slate-400">Operator</dt>
                            <dd class="truncate text-right text-slate-600 dark:text-slate-300">
                                {{ $student->assignee?->name ?? $student->petugas_nama ?? '—' }}
                            </dd>
                        </div>
                    </dl>
                </a>
            @endforeach
        </div>

        <div class="card hidden overflow-hidden md:block">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-white/5 dark:bg-white/[0.02]">
                        <tr>
                            <th class="px-4 py-3 font-semibold">Mahasiswa</th>
                            <th class="px-4 py-3 font-semibold">Wilayah</th>
                            <th class="hidden px-4 py-3 font-semibold xl:table-cell">Pokjar</th>
                            <th class="px-4 py-3 font-semibold">Kondisi</th>
                            <th class="hidden px-4 py-3 font-semibold lg:table-cell">Operator</th>
                            <th class="px-4 py-3 font-semibold">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                        @foreach ($students as $student)
                            <tr class="transition hover:bg-slate-50 dark:hover:bg-white/[0.02]">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <span class="avatar h-9 w-9 shrink-0 text-xs">{{ $student->initial() }}</span>
                                        <div class="min-w-0">
                                            <p class="truncate font-semibold text-slate-800 dark:text-white">{{ $student->name() }}</p>
                                            <p class="font-mono text-[11px] text-slate-400">
                                                {{ $student->nim }}@if ($student->nac) · {{ $student->nac }}@endif
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="max-w-[16rem] truncate px-4 py-3 text-slate-500 dark:text-slate-400">
                                    {{ $student->regionLabel() }}
                                </td>
                                <td class="hidden max-w-[12rem] truncate px-4 py-3 text-slate-500 xl:table-cell dark:text-slate-400">
                                    {{ $student->pokjar ?? '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <span class="{{ $student->kategori_masalah->badge() }}">
                                        {{ $student->kategori_masalah->short() }}
                                    </span>
                                    @if ($student->status_dp)
                                        <span class="badge-slate ml-1">{{ $student->status_dp }}</span>
                                    @endif
                                </td>
                                <td class="hidden max-w-[12rem] truncate px-4 py-3 text-slate-500 lg:table-cell dark:text-slate-400">
                                    {{ $student->assignee?->name ?? $student->petugas_nama ?? '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <span class="{{ $student->assignment_status->badge() }}">
                                        <x-icon :name="$student->assignment_status->icon()" class="h-3 w-3"/>
                                        {{ $student->assignment_status->label() }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('students.show', $student) }}" class="btn-outline btn-sm">Detail</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-6">{{ $students->links() }}</div>
    @endif

    @push('scripts')
        <script>
            function kecamatanPicker(endpoint, selected) {
                return {
                    kabupaten: @js($filters['kabupaten'] ?? ''),
                    kecamatan: selected,
                    options: @js($kecamatan),

                    async load() {
                        // Changing kabupaten invalidates the chosen kecamatan —
                        // keeping it would filter on a pair that cannot match.
                        this.kecamatan = '';

                        const url = new URL(endpoint, window.location.origin);
                        url.searchParams.set('kabupaten', this.kabupaten);

                        try {
                            const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
                            this.options = response.ok ? await response.json() : [];
                        } catch (e) {
                            this.options = [];
                        }
                    },
                };
            }
        </script>
    @endpush
</x-layouts.app>
