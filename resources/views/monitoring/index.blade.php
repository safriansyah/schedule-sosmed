<x-layouts.app title="Monitoring">
    <x-slot:header>
        <div>
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Monitoring</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Performa akun dan setiap postingan, langsung dari Instagram.
            </p>
        </div>

        @if ($account)
            <x-sync-button/>
        @endif
    </x-slot:header>

    @if (! $account)
        <div class="card">
            <x-empty-state icon="link" title="Belum ada akun terhubung"
                           description="Hubungkan akun sosmed dulu agar performanya bisa dipantau.">
                <x-slot:action>
                    @can(App\Enums\Permission::ManageAccounts->value)
                        <a href="{{ route('accounts.create') }}" class="btn-primary">
                            <x-icon name="plus" class="h-4 w-4"/> Hubungkan Akun
                        </a>
                    @endcan
                </x-slot:action>
            </x-empty-state>
        </div>
    @else

    {{-- Account + period picker --}}
    <form method="GET" class="card mb-6 flex flex-col gap-3 p-4 lg:flex-row lg:items-end">
        @if ($accounts->count() > 1)
            <div class="lg:w-56">
                <label for="account" class="label">Akun</label>
                <select id="account" name="account" class="input" onchange="this.form.submit()">
                    @foreach ($accounts as $option)
                        <option value="{{ $option->id }}" @selected($option->id === $account->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <div class="lg:w-48">
            <label for="period" class="label">Periode</label>
            <select id="period" name="period" class="input"
                    x-data x-on:change="$refs.custom && ($refs.custom.style.display = $event.target.value === 'custom' ? 'flex' : 'none')">
                @foreach ($periods as $value => [$label, $days])
                    <option value="{{ $value }}" @selected($period === $value)>{{ $label }}</option>
                @endforeach
                <option value="custom" @selected($period === 'custom')>Kustom</option>
            </select>
        </div>

        <div x-ref="custom" class="gap-3 sm:flex" style="display: {{ $period === 'custom' ? 'flex' : 'none' }}">
            <div>
                <label for="from" class="label">Dari</label>
                <input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="input">
            </div>
            <div>
                <label for="to" class="label">Sampai</label>
                <input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="input">
            </div>
        </div>

        <button class="btn-primary lg:ml-auto"><x-icon name="filter" class="h-4 w-4"/> Terapkan</button>
    </form>

    {{-- Account header --}}
    <div class="card mb-6 flex flex-col gap-4 p-5 sm:flex-row sm:items-center">
        <x-account-avatar :account="$account" size="h-14 w-14"/>

        <div class="min-w-0 flex-1">
            <p class="truncate text-base font-bold text-slate-800 dark:text-white">{{ $account->name }}</p>
            <p class="truncate text-sm text-slate-400">{{ $account->handle() }}</p>
        </div>

        <div class="grid grid-cols-4 gap-4 text-center sm:gap-6">
            <div>
                <p class="text-lg font-extrabold text-slate-800 dark:text-white">{{ number_format($account->followers_count) }}</p>
                <p class="text-[11px] text-slate-400">Followers</p>
            </div>
            <div>
                <p class="text-lg font-extrabold text-slate-800 dark:text-white">{{ number_format($totals['follows'] ?? 0) }}</p>
                <p class="text-[11px] text-slate-400">Following</p>
            </div>
            <div>
                <p class="text-lg font-extrabold text-slate-800 dark:text-white">{{ number_format($account->media_count) }}</p>
                <p class="text-[11px] text-slate-400">Postingan</p>
            </div>
            <div>
                <p class="text-lg font-extrabold text-slate-800 dark:text-white">{{ $posts?->total() ?? 0 }}</p>
                <p class="text-[11px] text-slate-400">Terpantau</p>
            </div>
        </div>
    </div>

    {{-- All headline numbers --}}
    @if ($totals)
        <x-metric-strip :totals="$totals" class="mb-6"/>
    @endif

    {{-- Comparison cards --}}
    @php
        $cards = [
            'followers' => ['user-plus', 'brand'],
            'likes' => ['heart', 'rose'],
            'comments' => ['message', 'cyan'],
            'views' => ['eye', 'amber'],
            'reach' => ['target', 'emerald'],
            'saves' => ['bookmark', 'brand'],
            'shares' => ['share', 'pink'],
            'interactions' => ['zap', 'pink'],
        ];
    @endphp

    <p class="mb-3 text-xs text-slate-400">
        {{ $comparison['period']['label'] }}
        ({{ $comparison['period']['from'] }} s/d {{ $comparison['period']['to'] }})
        · dibandingkan {{ $comparison['period']['previous_from'] }} s/d {{ $comparison['period']['previous_to'] }}
    </p>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($cards as $key => [$icon, $tone])
            @php $m = $comparison['metrics'][$key]; @endphp
            <x-stat-card
                :label="$m['label']"
                :value="number_format($m['current'])"
                :icon="$icon"
                :tone="$tone"
                :hint="'Sebelumnya '.number_format($m['previous'])"
                :current="$m['current']"
                :previous="$m['previous']"/>
        @endforeach
    </div>

    {{-- Trend — follows the selected period --}}
    @php $trendDays = count($trend['labels']); @endphp
    @if (array_sum($trend['series']['likes'] ?? []) + array_sum($trend['series']['views'] ?? []) > 0)
        <x-chart
            class="mt-6"
            title="Tren {{ $trendDays }} hari terakhir"
            subtitle="Pertambahan per hari"
            :height="320"
            :options="[
                'series' => [
                    ['name' => 'Like', 'data' => $trend['series']['likes'] ?? []],
                    ['name' => 'Views', 'data' => $trend['series']['views'] ?? []],
                    ['name' => 'Reach', 'data' => $trend['series']['reach'] ?? []],
                ],
                'chart' => ['type' => 'area'],
                'xaxis' => [
                    'categories' => $trend['labels'],
                    'axisBorder' => ['show' => false],
                    'tickAmount' => min($trendDays, 12),
                ],
                'fill' => ['type' => 'gradient', 'gradient' => ['opacityFrom' => 0.3, 'opacityTo' => 0.02]],
            ]"/>
    @else
        <div class="card mt-6 p-5">
            <p class="mb-2 text-sm font-semibold text-slate-700 dark:text-slate-200">Tren {{ $trendDays }} hari terakhir</p>
            <x-empty-state icon="chart" title="Data tren belum cukup"
                           description="Grafik muncul setelah ada minimal dua hari snapshot." class="!py-10"/>
        </div>
    @endif

    {{-- Latest comments across all posts --}}
    <div class="card mt-6 p-5">
        <div class="mb-4 flex items-center justify-between gap-2">
            <div>
                <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">Komentar terbaru</p>
                <p class="text-xs text-slate-400">Dari seluruh postingan akun ini · disinkron tiap 5 jam</p>
            </div>
            <a href="{{ route('monitoring.comments', ['account' => $account->id]) }}"
               class="btn-outline btn-sm shrink-0">
                <x-icon name="message" class="h-3.5 w-3.5"/> Semua komentar
            </a>
        </div>

        @forelse ($recentComments as $comment)
            <x-comment-item :comment="$comment" :show-post="true"/>
        @empty
            <x-empty-state icon="message" title="Belum ada komentar tersinkron"
                           description="Komentar publik ditarik otomatis setiap 5 jam. Jalankan sinkron atau tunggu jadwal berikutnya."
                           class="!py-8"/>
        @endforelse
    </div>

    {{-- Posts --}}
    <div class="mt-8 mb-4 flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-base font-bold text-slate-800 dark:text-white">
            Postingan <span class="text-sm font-normal text-slate-400">({{ number_format($posts->total()) }})</span>
        </h2>
        <span class="text-xs text-slate-400">Klik untuk detail performa</span>
    </div>

    {{-- Post filters — keeps a 1000-post account navigable --}}
    <form method="GET" class="card mb-5 flex flex-col gap-3 p-4 lg:flex-row lg:items-end">
        <input type="hidden" name="account" value="{{ $account->id }}">
        <input type="hidden" name="period" value="{{ $period }}">

        <div class="flex-1">
            <label for="q" class="label">Cari caption</label>
            <div class="relative">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"/>
                <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="input pl-9" placeholder="Kata kunci…">
            </div>
        </div>

        <div class="lg:w-40">
            <label for="type" class="label">Tipe</label>
            <select id="type" name="type" class="input">
                <option value="">Semua</option>
                @foreach (['FEED' => 'Feed', 'REELS' => 'Reels', 'STORY' => 'Story'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['type'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="lg:w-40">
            <label for="origin" class="label">Sumber</label>
            <select id="origin" name="origin" class="input">
                <option value="">Semua</option>
                <option value="app" @selected(($filters['origin'] ?? null) === 'app')>Dari aplikasi</option>
                <option value="external" @selected(($filters['origin'] ?? null) === 'external')>Luar aplikasi</option>
            </select>
        </div>

        <div class="lg:w-48">
            <label for="sort" class="label">Urutkan</label>
            <select id="sort" name="sort" class="input">
                @foreach ($sorts as $value => [$label, $expr])
                    <option value="{{ $value }}" @selected(($filters['sort'] ?? 'recent') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex gap-2">
            <button class="btn-primary"><x-icon name="filter" class="h-4 w-4"/> Terapkan</button>
            @if (array_filter(Arr::only($filters, ['q', 'type', 'sort', 'origin'])))
                <a href="{{ route('monitoring.index', ['account' => $account->id, 'period' => $period]) }}"
                   class="btn-outline"><x-icon name="x" class="h-4 w-4"/></a>
            @endif
        </div>
    </form>

    @if ($posts->isNotEmpty())
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($posts as $post)
                <a href="{{ route('monitoring.show', $post) }}" class="card-glow group flex flex-col overflow-hidden">
                    <div class="relative aspect-square bg-slate-100 dark:bg-white/5">
                        <x-remote-image class="h-full w-full" :src="$post->thumbnail()"
                                        label="Foto belum tersimpan"/>

                        <span class="absolute left-2 top-2 badge-slate shadow-sm">
                            {{ $post->isReel() ? 'Reels' : ucfirst(strtolower($post->product_type ?? 'Feed')) }}
                        </span>

                        @if ($post->content_id)
                            <span class="absolute right-2 top-2 badge-violet shadow-sm">Dari app</span>
                        @endif
                    </div>

                    <div class="flex flex-1 flex-col p-4">
                        <p class="line-clamp-2 text-sm text-slate-700 dark:text-slate-300">{{ $post->shortCaption(80) }}</p>
                        <p class="mt-1 text-xs text-slate-400">{{ $post->posted_at?->translatedFormat('d M Y, H:i') }}</p>

                        <div class="mt-3 grid grid-cols-4 gap-1 border-t border-slate-100 pt-3 text-center dark:border-white/5">
                            @foreach ([['heart', $post->stat_likes], ['message', $post->stat_comments], ['eye', $post->stat_views], ['target', $post->stat_reach]] as [$icon, $value])
                                <div>
                                    <x-icon :name="$icon" class="mx-auto h-3.5 w-3.5 text-slate-400"/>
                                    <p class="mt-0.5 text-xs font-bold text-slate-700 dark:text-slate-200">
                                        {{ number_format($value ?? 0) }}
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </a>
            @endforeach
        </div>

        @if ($posts->hasPages())
            <div class="mt-6">{{ $posts->links() }}</div>
        @endif
    @else
        <div class="card">
            <x-empty-state icon="image" title="Belum ada postingan terpantau"
                           description="Jalankan sinkronisasi untuk menarik postingan dari Instagram."/>
        </div>
    @endif

    @endif
</x-layouts.app>
