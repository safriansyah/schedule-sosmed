@use('Illuminate\Support\Number')

@php
    $s = $analytics['summary'];
    $f = $analytics['followers'];
    $ins = $analytics['insights'];
@endphp

{{-- KPI cards --}}
<div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
    <x-stat-card label="Total data" :value="Number::format($s['total'])" icon="database" tone="brand"/>
    <x-stat-card label="Valid" :value="Number::format($s['valid'])" icon="check" tone="emerald"
                 hint="{{ $s['valid_rate'] }}% valid"/>
    <x-stat-card label="Tidak valid" :value="Number::format($s['invalid'])" icon="x" tone="rose"/>
    <x-stat-card label="Memenuhi syarat" :value="Number::format($s['qualified'])" icon="star" tone="amber"
                 hint="{{ $s['qualified_rate'] }}% memenuhi syarat"/>
</div>

<div class="mt-4 grid grid-cols-2 gap-4 xl:grid-cols-4">
    <x-stat-card label="Total jangkauan" :value="Number::abbreviate($ins['reach'])" icon="globe" tone="cyan"/>
    <x-stat-card label="Rata-rata pengikut" :value="Number::abbreviate($f['avg'])" icon="trend" tone="brand"/>
    <x-stat-card label="Median pengikut" :value="Number::abbreviate($f['median'])" icon="activity" tone="emerald"/>
    <x-stat-card label="Pengikut terbanyak" :value="Number::abbreviate($f['max'])" icon="crown" tone="amber"/>
</div>

{{-- Charts row 1 --}}
<div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
    <x-chart title="Distribusi platform" icon="pie" :height="330"
             subtitle="Jumlah akun per platform"
             :options="[
                'chart' => ['type' => 'donut', 'height' => 330],
                'series' => array_map('intval', array_column($analytics['platform_distribution'], 'count')),
                'labels' => array_column($analytics['platform_distribution'], 'label'),
                'legend' => ['position' => 'bottom'],
                'plotOptions' => ['pie' => ['donut' => ['size' => '68%', 'labels' => ['show' => true, 'total' => ['show' => true, 'label' => 'Akun']]]]],
                'stroke' => ['width' => 0],
             ]" />

    <x-chart class="lg:col-span-2" title="Kelompok jumlah pengikut" icon="hash" :height="330"
             subtitle="Sebaran audiens berdasarkan ukuran akun"
             :options="[
                'chart' => ['type' => 'bar', 'height' => 330],
                'series' => [[ 'name' => 'Akun', 'data' => array_map('intval', array_column($analytics['buckets'], 'count')) ]],
                'xaxis' => ['categories' => array_column($analytics['buckets'], 'label')],
                'plotOptions' => ['bar' => ['borderRadius' => 8, 'columnWidth' => '50%', 'distributed' => true]],
                'legend' => ['show' => false],
             ]" />
</div>

{{-- Charts row 2 --}}
<div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
    <x-chart class="lg:col-span-2" title="Distribusi bertingkat" icon="trend" :height="320"
             subtitle="Rata-rata pengikut pada 20 tingkat berurutan"
             :options="[
                'chart' => ['type' => 'area', 'height' => 320, 'sparkline' => ['enabled' => false]],
                'series' => [[ 'name' => 'Rata-rata pengikut', 'data' => $analytics['growth_curve'] ]],
                'xaxis' => ['categories' => range(1, max(1, count($analytics['growth_curve']))), 'labels' => ['show' => false], 'axisTicks' => ['show' => false]],
                'fill' => ['type' => 'gradient', 'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.45, 'opacityTo' => 0.05]],
                'stroke' => ['curve' => 'smooth', 'width' => 3],
             ]" />

    <x-chart title="Status validasi" icon="shield" :height="320"
             subtitle="Valid vs tidak valid · memenuhi syarat vs tidak"
             :options="[
                'chart' => ['type' => 'radialBar', 'height' => 320],
                'series' => [$s['valid_rate'], $s['qualified_rate']],
                'labels' => ['Valid %', 'Memenuhi syarat %'],
                'plotOptions' => ['radialBar' => ['hollow' => ['size' => '45%'], 'track' => ['margin' => 10]]],
             ]" />
</div>

{{-- Heatmap + leaderboard --}}
<div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
    <x-chart class="lg:col-span-2" title="Heatmap platform × pengikut" icon="grid" :height="340"
             subtitle="Konsentrasi akun berdasarkan platform dan ukuran audiens"
             :options="[
                'chart' => ['type' => 'heatmap', 'height' => 340],
                'series' => collect($analytics['heatmap']['series'])->map(fn($r) => [
                    'name' => $r['platform'],
                    'data' => collect($r['data'])->map(fn($v, $i) => ['x' => $analytics['heatmap']['labels'][$i], 'y' => (int) $v])->values()->all(),
                ])->all(),
                'dataLabels' => ['enabled' => true],
                'colors' => ['#6366f1'],
                'plotOptions' => ['heatmap' => ['radius' => 6, 'shadeIntensity' => 0.6]],
             ]" />

    <div class="card p-5">
        <div class="mb-4 flex items-center gap-2">
            <span class="grid h-7 w-7 place-items-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-500/10">
                <x-icon name="crown" class="w-4 h-4"/>
            </span>
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Peringkat — 10 Teratas</h3>
        </div>
        <div class="space-y-1">
            @foreach ($analytics['leaderboard'] as $row)
                <div class="flex items-center gap-3 rounded-xl px-2 py-2 transition hover:bg-slate-50 dark:hover:bg-white/5">
                    <span @class([
                        'grid h-7 w-7 shrink-0 place-items-center rounded-lg text-xs font-bold',
                        'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300' => $row['rank'] <= 3,
                        'bg-slate-100 text-slate-500 dark:bg-white/5' => $row['rank'] > 3,
                    ])>{{ $row['rank'] }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $row['name'] }}</p>
                        <p class="truncate text-xs text-slate-400">{{ '@'.$row['username'] }}</p>
                    </div>
                    @if ($row['is_qualified']) <x-icon name="star" class="w-3.5 h-3.5 text-amber-400"/> @endif
                    <span class="badge-blue">{{ Number::abbreviate($row['followers']) }}</span>
                </div>
            @endforeach
        </div>
    </div>
</div>

{{-- Insight strip --}}
<div class="mt-6 grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
    @foreach ([
        ['Rata-rata postingan', Number::format($ins['avg_posts'], 1), 'hash'],
        ['Rata-rata mengikuti', Number::abbreviate($ins['avg_following']), 'users'],
        ['Rasio pengikut / mengikuti', $ins['follower_following_ratio'].'×', 'trend'],
        ['Akun > 10K', Number::format($ins['accounts_over_10k']), 'arrow-up'],
        ['Akun > 100K', Number::format($ins['accounts_over_100k']), 'crown'],
        ['Tingkat engagement', $ins['follower_following_ratio'] >= 5 ? 'Tinggi' : ($ins['follower_following_ratio'] >= 2 ? 'Sedang' : 'Rendah'), 'sparkles'],
    ] as [$label, $value, $icon])
        <div class="card p-4">
            <span class="grid h-8 w-8 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10">
                <x-icon :name="$icon" class="w-4 h-4"/>
            </span>
            <p class="mt-3 text-xl font-bold text-slate-800 dark:text-white">{{ $value }}</p>
            <p class="text-[11px] text-slate-400">{{ $label }}</p>
        </div>
    @endforeach
</div>
