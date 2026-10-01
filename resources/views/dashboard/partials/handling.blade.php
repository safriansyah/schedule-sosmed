{{--
    Penanganan: mahasiswa yang dibagikan, tiket, dan berapa banyak komentar
    sudah berubah jadi tiket.

    Numbers come from StudentStats / DashboardService, never recomputed here —
    the dashboard and the list screens must always agree.
--}}
<div class="mt-6">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-base font-bold text-slate-800 dark:text-white">Penanganan</h2>

        <div class="flex gap-2">
            @can(\App\Enums\Permission::ViewStudents->value)
                <a href="{{ route('students.index') }}" class="btn-outline btn-sm">
                    <x-icon name="users" class="h-3.5 w-3.5"/> Mahasiswa
                </a>
            @endcan
            @can(\App\Enums\Permission::ViewTickets->value)
                <a href="{{ route('tickets.index') }}" class="btn-outline btn-sm">
                    <x-icon name="file-text" class="h-3.5 w-3.5"/> Tiket
                </a>
            @endcan
        </div>
    </div>

    @if ($handling['students'])
        <div class="mb-4 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-5">
            <x-stat-card label="Total mahasiswa" :value="number_format($handling['students']['total'])"
                         icon="users" tone="brand" :href="route('students.index')"/>
            <x-stat-card label="Belum assigned" :value="number_format($handling['students']['unassigned'])"
                         icon="inbox" :tone="$handling['students']['unassigned'] > 0 ? 'amber' : 'slate'"/>
            <x-stat-card label="Assigned" :value="number_format($handling['students']['assigned'])"
                         icon="user-plus" tone="cyan"/>
            <x-stat-card label="Sedang follow up" :value="number_format($handling['students']['follow_up'])"
                         icon="phone" tone="violet"/>
            <x-stat-card label="Selesai" :value="number_format($handling['students']['resolved'] + $handling['students']['closed'])"
                         icon="check-circle" tone="emerald"/>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        @if ($handling['tickets'])
            <div class="card p-5">
                <h3 class="mb-4 text-sm font-bold text-slate-800 dark:text-white">Tiket</h3>

                <div class="space-y-2">
                    @foreach (\App\Enums\TicketStatus::cases() as $status)
                        @php $count = $handling['tickets'][$status->value] ?? 0; @endphp
                        <a href="{{ route('tickets.index', ['status' => $status->value]) }}"
                           class="flex items-center justify-between gap-3 rounded-lg px-2 py-1.5 transition hover:bg-slate-50 dark:hover:bg-white/[0.03]">
                            <span class="{{ $status->badge() }}">
                                <x-icon :name="$status->icon()" class="h-3 w-3"/> {{ $status->label() }}
                            </span>
                            <span class="font-bold tabular-nums text-slate-700 dark:text-slate-200">
                                {{ number_format($count) }}
                            </span>
                        </a>
                    @endforeach
                </div>

                @if (($handling['tickets']['mine'] ?? 0) > 0)
                    <a href="{{ route('tickets.mine') }}"
                       class="mt-3 block border-t border-slate-100 pt-3 text-xs text-brand-600 hover:underline dark:border-white/5 dark:text-brand-400">
                        {{ $handling['tickets']['mine'] }} tiket aktif ditugaskan ke Anda →
                    </a>
                @endif
            </div>
        @endif

        @if ($handling['social'])
            <div class="card p-5">
                <h3 class="mb-4 text-sm font-bold text-slate-800 dark:text-white">Sosial Media → Tiket</h3>

                @php
                    $total = max(1, $handling['social']['comments']);
                    $converted = round($handling['social']['with_ticket'] / $total * 100);
                @endphp

                <p class="text-3xl font-extrabold tracking-tight text-slate-800 dark:text-white">
                    {{ number_format($handling['social']['with_ticket']) }}
                </p>
                <p class="mt-0.5 text-xs text-slate-400">
                    dari {{ number_format($handling['social']['comments']) }} komentar & DM masuk
                </p>

                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-white/5">
                    <div class="h-full rounded-full bg-brand-500" style="width: {{ $converted }}%"></div>
                </div>

                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex items-center justify-between">
                        <dt class="text-slate-400">Sudah jadi tiket</dt>
                        <dd class="font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">
                            {{ number_format($handling['social']['with_ticket']) }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-slate-400">Belum jadi tiket</dt>
                        <dd class="font-semibold tabular-nums text-slate-700 dark:text-slate-200">
                            {{ number_format($handling['social']['without_ticket']) }}
                        </dd>
                    </div>
                </dl>
            </div>
        @endif

        @if ($handling['workload']->isNotEmpty())
            <div class="card overflow-hidden">
                <div class="border-b border-slate-200 p-4 dark:border-white/5">
                    <h3 class="text-sm font-bold text-slate-800 dark:text-white">Beban per Operator</h3>
                </div>

                <div class="max-h-64 overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-400 dark:bg-ink-900">
                            <tr>
                                <th class="px-4 py-2 font-semibold">Operator</th>
                                <th class="px-4 py-2 text-right font-semibold">FU</th>
                                <th class="px-4 py-2 text-right font-semibold">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                            @foreach ($handling['workload'] as $row)
                                <tr>
                                    <td class="truncate px-4 py-2 text-slate-600 dark:text-slate-300">{{ $row->name }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-slate-500">{{ number_format($row->follow_up) }}</td>
                                    <td class="px-4 py-2 text-right font-bold tabular-nums text-slate-700 dark:text-slate-200">
                                        {{ number_format($row->total) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</div>
