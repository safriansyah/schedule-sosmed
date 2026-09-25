@php
    /**
     * The CRM block on the dashboard.
     *
     * Ordered by what demands action soonest: breaches, then the urgent queue,
     * then the descriptive numbers. A manager should be able to stop reading
     * after the first row on a good day.
     */
    $stats = $crm['stats'];
    $sla   = $crm['sla'];
@endphp

<section class="mt-8">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="flex items-center gap-2 text-base font-bold text-slate-800 dark:text-white">
                <x-icon name="inbox" class="h-5 w-5 text-brand-500"/> Interaksi &amp; Sentimen
            </h2>
            <p class="mt-0.5 text-sm text-slate-400">
                Komentar &amp; pesan masuk dari semua kanal — 7 hari terakhir.
            </p>
        </div>

        <a href="{{ route('interactions.index') }}" class="btn-outline">
            Buka Inbox <x-icon name="chevron-right" class="h-4 w-4"/>
        </a>
    </div>

    {{-- Row 1: what needs doing --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Lewat batas waktu" :value="number_format($stats['sla_breach'])"
                     icon="alert" tone="rose" :tint="$stats['sla_breach'] > 0"
                     :href="route('interactions.index', ['tab' => 'urgent'])"
                     hint="Mendesak, belum dibalas > {{ config('crm.sla.urgent_hours') }} jam"/>

        <x-stat-card label="Mendesak belum selesai" :value="number_format($stats['urgent_open'])"
                     icon="flame" tone="amber"
                     :href="route('interactions.index', ['tab' => 'urgent'])"
                     hint="Perlu ditangani"/>

        <x-stat-card label="Perlu dibalas" :value="number_format($stats['needs_reply'])"
                     icon="reply" tone="cyan"
                     :href="route('interactions.index', ['tab' => 'needs_reply'])"
                     hint="{{ number_format($stats['questions']) }} di antaranya pertanyaan"/>

        <x-stat-card label="Ketepatan respons" :value="$sla['rate'] !== null ? $sla['rate'].'%' : '—'"
                     icon="clock" :tone="($sla['rate'] ?? 100) >= 80 ? 'emerald' : 'amber'"
                     :hint="$sla['answered'] > 0
                        ? 'Median '.$sla['median_hours'].' jam · '.$sla['answered'].' dibalas (30 hari)'
                        : 'Belum ada yang dibalas'"/>
    </div>

    {{-- Row 2: trend + the queue itself --}}
    <div class="mt-4 grid gap-4 xl:grid-cols-3">
        <x-chart
            class="xl:col-span-2"
            title="Tren Sentimen"
            subtitle="14 hari terakhir"
            :height="280"
            :options="[
                'series' => [
                    ['name' => 'Positif', 'data' => $crm['trend']['positive']],
                    ['name' => 'Netral',  'data' => $crm['trend']['neutral']],
                    ['name' => 'Negatif', 'data' => $crm['trend']['negative']],
                ],
                'chart' => ['type' => 'bar', 'stacked' => true],
                'xaxis' => ['categories' => $crm['trend']['labels']],
                // Same colours as the sentiment badges everywhere else, so the
                // chart needs no legend lookup to read.
                'colors' => [
                    App\Enums\Sentiment::Positive->color(),
                    App\Enums\Sentiment::Neutral->color(),
                    App\Enums\Sentiment::Negative->color(),
                ],
                'plotOptions' => ['bar' => ['borderRadius' => 4, 'columnWidth' => '60%']],
                'legend' => ['position' => 'top', 'horizontalAlign' => 'right'],
                'dataLabels' => ['enabled' => false],
            ]"/>

        <div class="card p-5">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Antrean Mendesak</h3>
                @if ($crm['urgent']->isNotEmpty())
                    <span class="badge-red">{{ $stats['urgent_open'] }}</span>
                @endif
            </div>

            {{-- Oldest first: the newest angry comment is not the one most at
                 risk of being forgotten. --}}
            <div class="space-y-2">
                @forelse ($crm['urgent'] as $interaction)
                    <a href="{{ route('interactions.show', $interaction) }}"
                       class="block rounded-xl border border-rose-500/20 bg-rose-500/[0.04] p-3 transition hover:border-rose-500/40">
                        <p class="flex items-center gap-2 text-xs">
                            <span class="max-w-[10rem] truncate font-semibold text-slate-700 dark:text-slate-200">{{ $interaction->handle() }}</span>
                            <span class="text-slate-400">· {{ $interaction->occurred_at?->diffForHumans() }}</span>
                        </p>
                        <p class="mt-1 line-clamp-2 text-xs text-slate-600 dark:text-slate-300">
                            {{ $interaction->shortText(90) }}
                        </p>
                        @if ($interaction->isBreachingSla())
                            <p class="mt-1.5 text-[11px] font-semibold text-rose-500">
                                <x-icon name="clock" class="inline h-3 w-3"/>
                                Telat {{ round($interaction->waitingHours() - $interaction->slaHours()) }} jam
                            </p>
                        @endif
                    </a>
                @empty
                    <x-empty-state icon="check-circle" title="Aman"
                                   description="Tidak ada komentar mendesak yang menunggu."
                                   class="!py-8"/>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Row 3: who is carrying what. Only shown to whoever may reassign. --}}
    @can(App\Enums\Permission::AssignInteractions->value)
        @if ($crm['workload']->isNotEmpty())
            <div class="card mt-4 p-5">
                <h3 class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-200">Beban Petugas</h3>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ($crm['workload'] as $row)
                        <div class="flex items-center gap-3 rounded-xl bg-slate-50 p-3 dark:bg-white/5">
                            <span class="avatar h-9 w-9 shrink-0 text-xs">
                                {{ strtoupper(mb_substr($row->assignee?->name ?? '?', 0, 1)) }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    {{ $row->assignee?->name ?? 'Tidak diketahui' }}
                                </p>
                                <p class="text-[11px] text-slate-400">
                                    {{ $row->total }} terbuka
                                    @if ($row->urgent > 0)
                                        · <span class="font-semibold text-rose-500">{{ $row->urgent }} mendesak</span>
                                    @endif
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endcan
</section>
