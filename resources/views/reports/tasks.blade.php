@php
    use App\Enums\TaskStatus;
@endphp

<x-layouts.app title="Laporan Task Management">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Laporan Task Management</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Khusus perencanaan kerja tim — terpisah dari tiket dan data mahasiswa.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('reports.index') }}" class="btn-outline">
                <x-icon name="chevron-left" class="h-4 w-4"/> Semua Laporan
            </a>
            <a href="{{ route('tasks.index') }}" class="btn-primary">
                <x-icon name="calendar" class="h-4 w-4"/> Papan Rencana
            </a>
        </div>
    </x-slot:header>

    <form method="GET" class="card mb-6 p-4">
        <div class="grid gap-3 sm:grid-cols-3">
            <div>
                <label for="from" class="label">Dari</label>
                <input id="from" name="from" type="date" value="{{ $from->toDateString() }}" class="input">
            </div>
            <div>
                <label for="to" class="label">Sampai</label>
                <input id="to" name="to" type="date" value="{{ $to->toDateString() }}" class="input">
            </div>
            <div class="flex items-end">
                <button class="btn-primary w-full"><x-icon name="filter" class="h-4 w-4"/> Terapkan</button>
            </div>
        </div>
        <p class="mt-2 text-[11px] text-slate-400">
            Rentang tanggal hanya memengaruhi tabel aktivitas di bawah; jumlah task dihitung keseluruhan.
        </p>
    </form>

    <div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-6">
        <x-stat-card label="Total task" :value="number_format($stats['total'])" icon="calendar" tone="brand"/>
        <x-stat-card label="Direncanakan" :value="number_format($stats['planned'])" icon="inbox" tone="slate"/>
        <x-stat-card label="Berjalan" :value="number_format($stats['in_progress'])" icon="clock" tone="cyan"/>
        <x-stat-card label="Selesai" :value="number_format($stats['completed'])" icon="check-circle" tone="emerald"/>
        <x-stat-card label="Lewat tenggat" :value="number_format($stats['overdue'])" icon="alert" tone="rose"/>
        <x-stat-card label="Publik" :value="number_format($stats['public'])" icon="eye" tone="violet"/>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="card overflow-hidden">
            <div class="border-b border-slate-200 p-4 dark:border-white/5">
                <h3 class="text-sm font-bold text-slate-800 dark:text-white">Beban per PIC</h3>
            </div>

            @if ($byPic->isEmpty())
                <x-empty-state icon="users" title="Belum ada task" description="Buat task dulu untuk melihat pembagiannya."/>
            @else
                <div class="divide-y divide-slate-100 md:hidden dark:divide-white/5">
                    @foreach ($byPic as $row)
                        <div class="flex items-center justify-between gap-3 p-4">
                            <p class="min-w-0 truncate font-semibold text-slate-800 dark:text-white">{{ $row->name }}</p>
                            <p class="shrink-0 text-sm">
                                <span class="font-extrabold text-slate-800 dark:text-white">{{ number_format($row->total) }}</span>
                                <span class="text-slate-400"> task · </span>
                                <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ number_format($row->done) }}</span>
                                <span class="text-slate-400"> selesai</span>
                            </p>
                        </div>
                    @endforeach
                </div>

                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-white/5 dark:bg-white/[0.02]">
                            <tr>
                                <th class="px-4 py-3 font-semibold">PIC</th>
                                <th class="px-4 py-3 text-right font-semibold">Task</th>
                                <th class="px-4 py-3 text-right font-semibold">Selesai</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                            @foreach ($byPic as $row)
                                <tr>
                                    <td class="px-4 py-2.5 font-medium text-slate-700 dark:text-slate-200">{{ $row->name }}</td>
                                    <td class="px-4 py-2.5 text-right font-bold text-slate-700 dark:text-slate-200">{{ number_format($row->total) }}</td>
                                    <td class="px-4 py-2.5 text-right text-emerald-600 dark:text-emerald-400">{{ number_format($row->done) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- The planner ticks, not the schedule. A task's start…due range is a
             plan; only a tick says the work happened, so counting ranges here
             would report intentions as results. --}}
        <div class="card overflow-hidden">
            <div class="border-b border-slate-200 p-4 dark:border-white/5">
                <h3 class="text-sm font-bold text-slate-800 dark:text-white">Aktivitas tercatat</h3>
                <p class="mt-0.5 text-xs text-slate-400">
                    Jumlah hari yang dicentang di papan rencana, {{ $from->translatedFormat('d M') }} – {{ $to->translatedFormat('d M Y') }}.
                    Total sepanjang masa: {{ number_format($stats['checked_days']) }} hari.
                </p>
            </div>

            @if ($activity->isEmpty())
                <x-empty-state icon="check" title="Belum ada centang"
                               description="Centang hari kerja di Papan Rencana agar aktivitasnya terekam di sini."/>
            @else
                <div class="divide-y divide-slate-100 dark:divide-white/5">
                    @foreach ($activity as $row)
                        <div class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                            <span class="min-w-0 flex-1 truncate text-slate-600 dark:text-slate-300">{{ $row->title }}</span>
                            <span class="shrink-0 font-bold text-slate-700 dark:text-slate-200">{{ number_format($row->days) }} hari</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="card mt-6 overflow-hidden">
        <div class="border-b border-slate-200 p-4 dark:border-white/5">
            <h3 class="text-sm font-bold text-slate-800 dark:text-white">Per status</h3>
        </div>
        <div class="divide-y divide-slate-100 dark:divide-white/5">
            @foreach ($statuses as $value => $label)
                @php $status = TaskStatus::from($value); @endphp
                <div class="flex items-center justify-between px-4 py-2.5 text-sm">
                    <span class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full {{ $status->barClass() }}"></span>
                        <span class="text-slate-600 dark:text-slate-300">{{ $label }}</span>
                    </span>
                    <span class="font-bold text-slate-700 dark:text-slate-200">{{ number_format($byStatus[$value] ?? 0) }}</span>
                </div>
            @endforeach
        </div>
    </div>
</x-layouts.app>
