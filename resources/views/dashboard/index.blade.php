<x-layouts.app title="Dashboard">
    <x-slot:header>
        <div>
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Dashboard</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                {{ $windowLabel }} · {{ $from->translatedFormat('d M') }} – {{ $to->translatedFormat('d M Y') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            {{-- Period filter --}}
            <form method="GET" class="flex items-center gap-2">
                <select name="period" class="input !w-auto !py-2" onchange="this.form.submit()">
                    @foreach ($periods as $value => [$label, $days])
                        <option value="{{ $value }}" @selected($period === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </form>

            @can(App\Enums\Permission::ViewMonitoring->value)
                <x-sync-button :last-synced-at="$lastSyncedAt"/>
            @endcan

            @can('create', App\Models\Content::class)
                <a href="{{ route('contents.create') }}" class="btn-primary">
                    <x-icon name="plus" class="h-4 w-4"/> Buat Konten
                </a>
            @endcan
        </div>
    </x-slot:header>

    {{-- Automation health. First thing on the page: if the scheduler is down,
         every number below it is stale and should be read that way. --}}
    @if ($health)
        @include('dashboard.partials.health')
    @endif

    {{-- Workflow counters, each compared to the previous window --}}
    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        @foreach ($workflow as $key => $card)
            <x-stat-card
                tint
                :label="$card['label']"
                :value="number_format($card['current'])"
                :icon="$card['icon']"
                :tone="$card['tone']"
                :current="$card['current']"
                :previous="$card['previous']"
                :href="$card['status'] ? route('contents.index', ['status' => $card['status']]) : route('contents.index')"/>
        @endforeach
    </div>

    {{-- Charts --}}
    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <x-chart
            class="xl:col-span-2"
            title="Produksi Konten"
            :subtitle="'Dibuat vs terbit — '.$windowLabel"
            :height="300"
            :options="[
                'series' => [
                    ['name' => 'Dibuat', 'data' => $trend['created']],
                    ['name' => 'Terbit', 'data' => $trend['published']],
                ],
                'chart' => ['type' => 'area'],
                'xaxis' => ['categories' => $trend['labels'], 'axisBorder' => ['show' => false], 'tickAmount' => 10],
                'fill' => ['type' => 'gradient', 'gradient' => ['opacityFrom' => 0.3, 'opacityTo' => 0.02]],
            ]"/>

        @if ($breakdown->isNotEmpty())
            <x-chart
                title="Distribusi Status"
                subtitle="Seluruh konten"
                :height="300"
                :options="[
                    'series' => $breakdown->pluck('value')->all(),
                    'chart' => ['type' => 'donut'],
                    'labels' => $breakdown->pluck('label')->all(),
                    'colors' => $breakdown->pluck('color')->all(),
                    'legend' => ['position' => 'bottom'],
                    'plotOptions' => ['pie' => ['donut' => ['size' => '68%']]],
                ]"/>
        @else
            <div class="card p-5">
                <p class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-200">Distribusi Status</p>
                <x-empty-state icon="pie" title="Belum ada data" class="!py-10"/>
            </div>
        @endif
    </div>

    {{-- Interaksi & sentimen — only for roles that work the inbox. Placed
         above account performance because it is about people waiting for a
         reply, not about numbers that can be read tomorrow. --}}
    @if ($crm)
        @include('dashboard.partials.crm')
    @endif

    {{-- Mahasiswa & tiket. Below the inbox because a comment waiting for a
         reply is more urgent than a caseload total. --}}
    @if ($handling)
        @include('dashboard.partials.handling')
    @endif

    {{-- Account performance --}}
    @if ($accounts->isNotEmpty())
        <div class="mt-6">
            <h2 class="mb-3 text-base font-bold text-slate-800 dark:text-white">Performa Akun</h2>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($accounts as $row)
                    <a href="{{ route('monitoring.index', ['account' => $row['account']->id]) }}"
                       class="card-glow block p-5">
                        <div class="flex items-center gap-3">
                            <x-account-avatar :account="$row['account']" size="h-11 w-11"
                                              ring="ring-2 ring-brand-500/30"/>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-slate-800 dark:text-white">{{ $row['account']->name }}</p>
                                <p class="truncate text-xs text-slate-400">{{ $row['account']->handle() }}</p>
                            </div>

                            <x-delta :current="$row['growth']['current']" :previous="$row['growth']['previous']"/>
                        </div>

                        <div class="mt-4 grid grid-cols-4 gap-2 border-t border-slate-100 pt-4 text-center dark:border-white/5">
                            @foreach ([
                                ['Followers', $row['followers']],
                                ['Postingan', $row['posts']],
                                ['Like', $row['likes']['current']],
                                ['Views', $row['views']['current']],
                            ] as [$label, $value])
                                <div>
                                    <p class="text-sm font-bold text-slate-800 dark:text-white">{{ number_format($value) }}</p>
                                    <p class="text-[11px] text-slate-400">{{ $label }}</p>
                                </div>
                            @endforeach
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Team output + upcoming --}}
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">

        {{-- Team --}}
        <div class="card p-5 lg:col-span-2">
            <p class="mb-4 text-sm font-semibold text-slate-700 dark:text-slate-200">
                Produktivitas Tim <span class="font-normal text-slate-400">· {{ $windowLabel }}</span>
            </p>

            @forelse ($team as $row)
                @php $rate = $row->total > 0 ? round($row->published / $row->total * 100) : 0; @endphp

                <div class="mb-3 last:mb-0">
                    <div class="mb-1.5 flex items-center gap-3">
                        <span class="avatar h-7 w-7 text-xs">{{ $row->creator?->initial() ?? '?' }}</span>
                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-700 dark:text-slate-200">
                            {{ $row->creator?->name ?? 'Tidak diketahui' }}
                        </span>
                        <span class="text-xs text-slate-400">
                            {{ $row->published }}/{{ $row->total }} terbit
                        </span>
                    </div>

                    <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-white/5">
                        <div class="h-full rounded-full bg-gradient-to-r from-brand-600 to-brand-400 transition-all"
                             style="width: {{ max($rate, 2) }}%"></div>
                    </div>
                </div>
            @empty
                <x-empty-state icon="users" title="Belum ada produksi"
                               description="Konten yang dibuat pada periode ini akan tampil di sini." class="!py-8"/>
            @endforelse
        </div>

        {{-- Upcoming --}}
        <div class="card p-5">
            <div class="mb-4 flex items-center justify-between">
                <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">Akan Terbit</p>
                <a href="{{ route('calendar.index') }}"
                   class="text-xs font-medium text-brand-600 transition hover:text-brand-500">Kalender</a>
            </div>

            @forelse ($upcoming as $content)
                <a href="{{ route('contents.show', $content) }}"
                   class="row-hover -mx-2 flex items-start gap-3 rounded-xl px-2 py-2.5">
                    <div class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-slate-100 text-slate-400 dark:bg-white/5">
                        <x-icon :name="$content->hasVideo() ? 'video' : 'image'" class="h-4 w-4"/>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-slate-700 dark:text-slate-200">{{ $content->title }}</p>
                        <p class="mt-0.5 text-xs text-slate-400">{{ $content->scheduled_at?->translatedFormat('d M, H:i') }}</p>
                    </div>
                </a>
            @empty
                <x-empty-state icon="calendar" title="Tidak ada jadwal" class="!py-8"/>
            @endforelse
        </div>
    </div>

    {{-- Personal / activity --}}
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        @if ($myWork->isNotEmpty())
            <div class="card p-5">
                <p class="mb-4 text-sm font-semibold text-slate-700 dark:text-slate-200">Perlu Tindakan Anda</p>
                @foreach ($myWork as $content)
                    <a href="{{ route('contents.show', $content) }}"
                       class="row-hover -mx-2 flex items-start gap-3 rounded-xl px-2 py-2.5">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-slate-700 dark:text-slate-200">{{ $content->title }}</p>
                            <p class="mt-0.5 text-xs text-slate-400">Diperbarui {{ $content->updated_at->diffForHumans() }}</p>
                        </div>
                        <x-status-badge :status="$content->status"/>
                    </a>
                @endforeach
            </div>
        @endif

        <div class="card p-5 {{ $myWork->isEmpty() ? 'lg:col-span-2' : '' }}">
            <div class="mb-4 flex items-center justify-between">
                <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">Aktivitas Terbaru</p>
                @can(App\Enums\Permission::ViewActivity->value)
                    <a href="{{ route('activities.index') }}"
                       class="text-xs font-medium text-brand-600 transition hover:text-brand-500">Lihat semua</a>
                @endcan
            </div>

            @forelse ($recentActivity as $activity)
                <div class="-mx-2 flex items-start gap-3 rounded-xl px-2 py-2">
                    <span class="badge-blue !h-7 !w-7 shrink-0 !justify-center !rounded-full !p-0">
                        <x-icon :name="$activity->icon()" class="h-3.5 w-3.5"/>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="line-clamp-2 text-sm text-slate-700 dark:text-slate-200">{{ $activity->description }}</p>
                        <p class="mt-0.5 text-xs text-slate-400">
                            {{ $activity->user?->name ?? 'Sistem' }} · {{ $activity->created_at->diffForHumans() }}
                        </p>
                    </div>
                </div>
            @empty
                <x-empty-state icon="activity" title="Belum ada aktivitas" class="!py-8"/>
            @endforelse
        </div>
    </div>
</x-layouts.app>
