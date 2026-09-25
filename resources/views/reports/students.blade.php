@php
    use App\Enums\StudentCondition;

    $withoutTicket = max(0, $total - $withTicket);
@endphp

<x-layouts.app title="Laporan Data Mahasiswa">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Laporan Data Mahasiswa</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Khusus data mahasiswa: kondisi, wilayah, dan pembagian ke operator.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('reports.index') }}" class="btn-outline">
                <x-icon name="chevron-left" class="h-4 w-4"/> Semua Laporan
            </a>
            @if ($canExport)
                <a href="{{ route('students.export', ['format' => 'xlsx']) }}" class="btn-primary">
                    <x-icon name="download" class="h-4 w-4"/> Export Mahasiswa
                </a>
            @endif
        </div>
    </x-slot:header>

    <div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-6">
        <x-stat-card label="Total" :value="number_format($students['total'])" icon="users" tone="brand"/>
        <x-stat-card label="Belum assigned" :value="number_format($students['unassigned'])" icon="inbox" tone="slate"/>
        <x-stat-card label="Assigned" :value="number_format($students['assigned'])" icon="user-plus" tone="cyan"/>
        <x-stat-card label="Follow up" :value="number_format($students['follow_up'])" icon="phone" tone="violet"/>
        <x-stat-card label="Selesai" :value="number_format($students['resolved'])" icon="check-circle" tone="emerald"/>
        <x-stat-card label="Ditutup" :value="number_format($students['closed'])" icon="ban" tone="amber"/>
    </div>

    {{-- The bridge to the ticket report: a student without a ticket has no
         follow-up history and no place for one, so this number is the honest
         measure of how much of the list is actually workable. --}}
    <div class="card mb-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="min-w-0">
                <h3 class="text-sm font-bold text-slate-800 dark:text-white">Cakupan tiket</h3>
                <p class="mt-0.5 text-xs text-slate-400">
                    Mahasiswa tanpa tiket belum punya tempat mencatat follow up.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-5 text-sm">
                <div>
                    <p class="text-xl font-extrabold text-emerald-600 dark:text-emerald-400">{{ number_format($withTicket) }}</p>
                    <p class="text-[11px] text-slate-400">sudah punya tiket</p>
                </div>
                <div>
                    <p class="text-xl font-extrabold text-slate-400">{{ number_format($withoutTicket) }}</p>
                    <p class="text-[11px] text-slate-400">belum</p>
                </div>

                @can(\App\Enums\Permission::AssignStudents->value)
                    @if ($withoutTicket > 0)
                        <a href="{{ route('students.unsigned') }}" class="btn-outline">
                            <x-icon name="file-text" class="h-4 w-4"/> Generate Ticket
                        </a>
                    @endif
                @endcan
            </div>
        </div>

        <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-white/5">
            <div class="h-full rounded-full bg-emerald-500/70"
                 style="width: {{ $total > 0 ? round($withTicket / $total * 100, 2) : 0 }}%"></div>
        </div>
    </div>

    <div class="mb-6 grid gap-6 lg:grid-cols-2">
        {{-- Per-operator: the workload table. --}}
        <div class="card overflow-hidden">
            <div class="border-b border-slate-200 p-4 dark:border-white/5">
                <h3 class="text-sm font-bold text-slate-800 dark:text-white">Statistik per Operator</h3>
            </div>

            @if ($workload->isEmpty())
                <x-empty-state icon="users" title="Belum ada pembagian"
                               description="Statistik muncul setelah mahasiswa dibagikan ke operator."/>
            @else
                {{-- Phone: the same four numbers, read down instead of across. --}}
                <div class="divide-y divide-slate-100 md:hidden dark:divide-white/5">
                    @foreach ($workload as $row)
                        <div class="flex items-center justify-between gap-3 p-4">
                            <div class="min-w-0">
                                <p class="truncate font-semibold text-slate-800 dark:text-white">{{ $row->name }}</p>
                                <p class="text-[11px] text-slate-400">
                                    {{ number_format($row->follow_up) }} follow up ·
                                    {{ number_format($row->resolved + $row->closed) }} selesai
                                </p>
                            </div>
                            <span class="shrink-0 text-lg font-extrabold text-slate-800 dark:text-white">
                                {{ number_format($row->total) }}
                            </span>
                        </div>
                    @endforeach
                </div>

                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-white/5 dark:bg-white/[0.02]">
                            <tr>
                                <th class="px-4 py-3 font-semibold">Operator</th>
                                <th class="px-4 py-3 text-right font-semibold">Total</th>
                                <th class="px-4 py-3 text-right font-semibold">Follow up</th>
                                <th class="px-4 py-3 text-right font-semibold">Selesai</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                            @foreach ($workload as $row)
                                <tr>
                                    <td class="px-4 py-2.5 font-medium text-slate-700 dark:text-slate-200">{{ $row->name }}</td>
                                    <td class="px-4 py-2.5 text-right font-bold text-slate-700 dark:text-slate-200">{{ number_format($row->total) }}</td>
                                    <td class="px-4 py-2.5 text-right text-slate-500 dark:text-slate-400">{{ number_format($row->follow_up) }}</td>
                                    <td class="px-4 py-2.5 text-right text-slate-500 dark:text-slate-400">{{ number_format($row->resolved + $row->closed) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Condition breakdown, using the real vocabulary from the import. --}}
        <div class="card overflow-hidden">
            <div class="border-b border-slate-200 p-4 dark:border-white/5">
                <h3 class="text-sm font-bold text-slate-800 dark:text-white">Sebaran Kondisi</h3>
            </div>

            <div class="divide-y divide-slate-100 dark:divide-white/5">
                @foreach ($conditions as $value => $label)
                    @php
                        $count = $byCondition[$value] ?? 0;
                        $condition = StudentCondition::from($value);
                    @endphp
                    @continue($count === 0)

                    <div class="px-4 py-2.5">
                        <div class="flex items-baseline justify-between gap-3 text-sm">
                            <span class="{{ $condition->badge() }}">{{ $condition->short() }}</span>
                            <span class="min-w-0 flex-1 truncate text-xs text-slate-400">{{ $label }}</span>
                            <span class="shrink-0 font-bold text-slate-700 dark:text-slate-200">{{ number_format($count) }}</span>
                        </div>

                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/5">
                            <div class="h-full rounded-full bg-brand-500/70"
                                 style="width: {{ $students['total'] > 0 ? round($count / $students['total'] * 100, 2) : 0 }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Region. `byRegion` carries an `unassigned` column the generic
         breakdown component has no place for, so this one is spelled out. --}}
    <div class="card overflow-hidden">
        <div class="border-b border-slate-200 p-4 dark:border-white/5">
            <h3 class="text-sm font-bold text-slate-800 dark:text-white">Sebaran Wilayah</h3>
            <p class="mt-0.5 text-xs text-slate-400">
                Kabupaten/kota — tingkat wilayah terdalam yang benar-benar terisi pada file import.
            </p>
        </div>

        @if ($byRegion->isEmpty())
            <x-empty-state icon="map-pin" title="Belum ada data wilayah"
                           description="Kolom kabupaten belum terisi pada data yang diimpor."/>
        @else
            <div class="divide-y divide-slate-100 md:hidden dark:divide-white/5">
                @foreach ($byRegion as $row)
                    <div class="p-4">
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="min-w-0 truncate font-semibold text-slate-800 dark:text-white">{{ $row->kabupaten }}</p>
                            <span class="shrink-0 text-lg font-extrabold text-slate-800 dark:text-white">
                                {{ number_format($row->total) }}
                            </span>
                        </div>
                        <p class="mt-0.5 text-[11px] text-slate-400">
                            {{ number_format($row->unassigned) }} belum assigned ·
                            <span class="text-emerald-600 dark:text-emerald-400">
                                {{ number_format($row->total - $row->unassigned) }} sudah dibagi
                            </span>
                        </p>
                    </div>
                @endforeach
            </div>

            <div class="hidden overflow-x-auto md:block">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-white/5 dark:bg-white/[0.02]">
                        <tr>
                            <th class="px-4 py-3 font-semibold">Kabupaten / Kota</th>
                            <th class="px-4 py-3 text-right font-semibold">Total</th>
                            <th class="px-4 py-3 text-right font-semibold">Belum assigned</th>
                            <th class="px-4 py-3 text-right font-semibold">Sudah dibagi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                        @foreach ($byRegion as $row)
                            <tr>
                                <td class="px-4 py-2.5 font-medium text-slate-700 dark:text-slate-200">{{ $row->kabupaten }}</td>
                                <td class="px-4 py-2.5 text-right font-bold text-slate-700 dark:text-slate-200">{{ number_format($row->total) }}</td>
                                <td class="px-4 py-2.5 text-right text-slate-500 dark:text-slate-400">{{ number_format($row->unassigned) }}</td>
                                <td class="px-4 py-2.5 text-right text-emerald-600 dark:text-emerald-400">
                                    {{ number_format($row->total - $row->unassigned) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-layouts.app>
