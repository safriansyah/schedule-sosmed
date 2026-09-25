<x-layouts.app title="Analytics">
    <x-slot:header>
        <div>
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Analytics</h1>
            <p class="mt-0.5 text-sm text-slate-400">Perbandingan performa antar periode dan waktu terbaik memposting.</p>
        </div>

        @if ($account && $accounts->count() > 1)
            <form method="GET">
                <select name="account" class="input !w-auto !py-2" onchange="this.form.submit()">
                    @foreach ($accounts as $option)
                        <option value="{{ $option->id }}" @selected($option->id === $account->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </form>
        @endif
    </x-slot:header>

    @if (! $account)
        <div class="card">
            <x-empty-state icon="chart" title="Belum ada akun terhubung"
                           description="Hubungkan akun sosmed dulu agar analitiknya bisa dihitung."/>
        </div>
    @else

    {{-- Overview — every current headline number --}}
    <x-metric-strip :totals="$totals" class="mb-6"/>

    {{-- Side-by-side comparison, the way it was specified --}}
    @php
        $metrics = [
            'followers' => ['Followers Baru', 'user-plus'],
            'likes' => ['Like', 'heart'],
            'comments' => ['Comment', 'message'],
            'views' => ['Views', 'eye'],
            'reach' => ['Reach', 'target'],
            'saves' => ['Save', 'bookmark'],
            'shares' => ['Share', 'share'],
            'interactions' => ['Interaksi', 'zap'],
        ];
    @endphp

    <div class="card overflow-hidden">
        {{-- Phone: one card per metric, periods stacked. The table is one
             column per period and grows with them, so it is the shape most
             likely to run off a small screen. --}}
        <div class="divide-y divide-slate-100 md:hidden dark:divide-white/5">
            @foreach ($metrics as $key => [$label, $icon])
                <div class="p-4">
                    <p class="flex items-center gap-2 font-semibold text-slate-800 dark:text-white">
                        <x-icon :name="$icon" class="h-4 w-4 text-slate-400"/> {{ $label }}
                    </p>

                    <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-2">
                        @foreach ($labels as $periodKey => [$periodLabel, $days])
                            @php $m = $periods[$periodKey]['metrics'][$key] ?? null; @endphp
                            <div class="min-w-0">
                                <dt class="text-[11px] text-slate-400">{{ $periodLabel }}</dt>
                                <dd>
                                    @if ($m)
                                        <span class="font-bold text-slate-800 dark:text-white">
                                            {{ number_format($m['current']) }}
                                        </span>
                                        <x-delta :current="$m['current']" :previous="$m['previous']" class="ml-1"/>
                                    @else
                                        <span class="text-slate-300">-</span>
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endforeach
        </div>

        <div class="hidden overflow-x-auto md:block">
            <table class="w-full min-w-[760px]">
                <thead class="border-b border-slate-100 dark:border-white/5">
                    <tr>
                        <th class="th">Metrik</th>
                        @foreach ($labels as $key => [$label, $days])
                            <th class="th text-right">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                    @foreach ($metrics as $key => [$label, $icon])
                        <tr class="row-hover">
                            <td class="td">
                                <span class="flex items-center gap-2 font-medium text-slate-700 dark:text-slate-200">
                                    <x-icon :name="$icon" class="h-4 w-4 text-slate-400"/> {{ $label }}
                                </span>
                            </td>

                            @foreach ($labels as $periodKey => $_)
                                @php $m = $periods[$periodKey]['metrics'][$key] ?? null; @endphp
                                <td class="td text-right">
                                    @if ($m)
                                        <span class="block font-bold text-slate-800 dark:text-white">
                                            {{ number_format($m['current']) }}
                                        </span>
                                        <span class="block text-[11px] text-slate-400">
                                            sblm {{ number_format($m['previous']) }}
                                        </span>
                                        <x-delta :current="$m['current']" :previous="$m['previous']" class="mt-1"/>
                                    @else
                                        <span class="text-slate-300">-</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Best time to post --}}
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-chart
            title="Performa per Jam Posting"
            subtitle="Rata-rata interaksi berdasarkan jam terbit"
            :height="280"
            :options="[
                'series' => [['name' => 'Rata-rata interaksi', 'data' => $byHour->pluck('avg')->all()]],
                'chart' => ['type' => 'bar'],
                'xaxis' => ['categories' => $byHour->pluck('label')->all()],
                'plotOptions' => ['bar' => ['borderRadius' => 4, 'columnWidth' => '60%']],
            ]">
            @if ($bestHour)
                <x-slot:actions>
                    <span class="badge-green">
                        <x-icon name="clock" class="h-3 w-3"/> Terbaik {{ $bestHour['label'] }}
                    </span>
                </x-slot:actions>
            @endif
        </x-chart>

        <x-chart
            title="Performa per Hari"
            subtitle="Rata-rata interaksi berdasarkan hari terbit"
            :height="280"
            :options="[
                'series' => [['name' => 'Rata-rata interaksi', 'data' => $byDay->pluck('avg')->all()]],
                'chart' => ['type' => 'bar'],
                'xaxis' => ['categories' => $byDay->pluck('label')->all()],
                'colors' => ['#06b6d4'],
                'plotOptions' => ['bar' => ['borderRadius' => 4, 'columnWidth' => '55%']],
            ]">
            @if ($bestDay)
                <x-slot:actions>
                    <span class="badge-green">
                        <x-icon name="calendar" class="h-3 w-3"/> Terbaik {{ $bestDay['label'] }}
                    </span>
                </x-slot:actions>
            @endif
        </x-chart>
    </div>

    @if ($bestHour && $bestDay)
        <div class="card mt-4 flex items-start gap-3 border-emerald-200 bg-emerald-50/50 p-4 dark:border-emerald-500/20 dark:bg-emerald-500/5">
            <x-icon name="sparkles" class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600 dark:text-emerald-400"/>
            <p class="text-sm text-emerald-800 dark:text-emerald-300">
                Berdasarkan {{ $byHour->sum('posts') }} postingan yang terpantau, waktu terbaik memposting adalah
                <b>{{ $bestDay['label'] }} sekitar pukul {{ $bestHour['label'] }}</b>
                (rata-rata {{ number_format($bestHour['avg'], 1) }} interaksi).
            </p>
        </div>
    @endif

    {{-- Follower growth --}}
    @php
        $followerStart = $followerSeries['followers'][0] ?? 0;
        $followerEnd = end($followerSeries['followers']) ?: 0;
        $followerGain = $followerEnd - $followerStart;
        $totalGained = array_sum(array_filter($followerSeries['gained'], fn ($g) => $g > 0));
        $totalLost = abs(array_sum(array_filter($followerSeries['gained'], fn ($g) => $g < 0)));
    @endphp

    <x-chart
        class="mt-6"
        title="Pertumbuhan Followers"
        subtitle="30 hari terakhir"
        :height="300"
        :options="[
            'series' => [['name' => 'Followers', 'data' => $followerSeries['followers']]],
            'chart' => ['type' => 'area'],
            'colors' => ['#8b5cf6'],
            'xaxis' => ['categories' => $followerSeries['labels'], 'tickAmount' => 10, 'axisBorder' => ['show' => false]],
            'fill' => ['type' => 'gradient', 'gradient' => ['opacityFrom' => 0.3, 'opacityTo' => 0.02]],
        ]">
        <x-slot:actions>
            <span class="{{ $followerGain >= 0 ? 'badge-green' : 'badge-red' }}">
                <x-icon :name="$followerGain >= 0 ? 'trend' : 'trend-down'" class="h-3 w-3"/>
                {{ $followerGain >= 0 ? '+' : '' }}{{ number_format($followerGain) }} (30 hari)
            </span>
        </x-slot:actions>
    </x-chart>

    <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <x-stat-card label="Followers Saat Ini" :value="number_format($followerEnd)" icon="users" tone="brand"/>
        <x-stat-card label="Follower Baru" :value="'+'.number_format($totalGained)" icon="trend" tone="emerald" hint="30 hari"/>
        <x-stat-card label="Unfollow" :value="number_format($totalLost)" icon="trend-down" tone="rose" hint="30 hari"/>
        <x-stat-card label="Net 30 Hari" :value="($followerGain >= 0 ? '+' : '').number_format($followerGain)"
                     :icon="$followerGain >= 0 ? 'trend' : 'trend-down'" :tone="$followerGain >= 0 ? 'emerald' : 'rose'"/>
    </div>

    {{-- 30-day trend --}}
    <x-chart
        class="mt-6"
        title="Tren 30 Hari"
        subtitle="Pertambahan harian"
        :height="320"
        :options="[
            'series' => [
                ['name' => 'Like', 'data' => $trend['series']['likes'] ?? []],
                ['name' => 'Comment', 'data' => $trend['series']['comments'] ?? []],
                ['name' => 'Views', 'data' => $trend['series']['views'] ?? []],
                ['name' => 'Reach', 'data' => $trend['series']['reach'] ?? []],
            ],
            'chart' => ['type' => 'area'],
            'xaxis' => ['categories' => $trend['labels'], 'tickAmount' => 10, 'axisBorder' => ['show' => false]],
            'fill' => ['type' => 'gradient', 'gradient' => ['opacityFrom' => 0.25, 'opacityTo' => 0.02]],
        ]"/>

    {{-- Top posts + newest comments --}}
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">

        <div class="card p-5">
            <p class="mb-4 text-sm font-semibold text-slate-700 dark:text-slate-200">Postingan Terbaik</p>

            @forelse ($topPosts as $post)
                <a href="{{ route('monitoring.show', $post->id) }}"
                   class="row-hover -mx-2 flex items-start gap-3 rounded-xl px-2 py-2.5">
                    {{-- Baris dari DB::table, jadi tidak punya accessor — lihat AccountMedia::imageUrl(). --}}
                    <x-remote-image class="h-12 w-12 shrink-0 rounded-lg"
                                    :src="App\Models\AccountMedia::imageUrl($post->thumbnail_path, $post->thumbnail_url)"/>

                    <div class="min-w-0 flex-1">
                        <p class="line-clamp-1 text-sm text-slate-700 dark:text-slate-200">
                            {{ Str::limit(str_replace("\n", ' ', $post->caption ?: 'Tanpa caption'), 50) }}
                        </p>
                        <p class="mt-0.5 flex flex-wrap gap-2 text-xs text-slate-400">
                            <span>❤ {{ number_format($post->likes) }}</span>
                            <span>💬 {{ number_format($post->comments) }}</span>
                            <span>👁 {{ number_format($post->views) }}</span>
                        </p>
                    </div>

                    <span class="badge-blue shrink-0">{{ number_format($post->interactions) }}</span>
                </a>
            @empty
                <x-empty-state icon="star" title="Belum ada data" class="!py-8"/>
            @endforelse
        </div>

        <div class="card p-5">
            <div class="mb-4 flex items-center justify-between gap-2">
                <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">Komentar Terbaru</p>
                <a href="{{ route('monitoring.comments', ['account' => $account->id]) }}"
                   class="text-xs font-medium text-brand-600 transition hover:text-brand-500">Lihat semua →</a>
            </div>

            @forelse ($recentComments as $comment)
                <x-comment-item :comment="$comment" :show-post="true"/>
            @empty
                <x-empty-state icon="message" title="Belum ada komentar"
                               description="Komentar publik ditarik otomatis setiap 5 jam."
                               class="!py-8"/>
            @endforelse
        </div>
    </div>

    @endif
</x-layouts.app>
