@props(['totals'])

{{-- Every current headline number for the account, in one compact strip. --}}
@php
    $er = $totals['engagement_rate'] ?? null;

    $items = [
        ['Followers', number_format($totals['followers']), 'users', 'text-brand-600 dark:text-brand-300'],
        ['Following', number_format($totals['follows']), 'user-plus', 'text-cyan-600 dark:text-cyan-400'],
        ['Postingan', number_format($totals['media_count']), 'image', 'text-slate-500 dark:text-slate-400'],
        ['Total Like', number_format($totals['likes']), 'heart', 'text-rose-600 dark:text-rose-400'],
        ['Total Komentar', number_format($totals['comments']), 'message', 'text-cyan-600 dark:text-cyan-400'],
        ['Total Views', number_format($totals['views']), 'eye', 'text-amber-600 dark:text-amber-400'],
        ['Total Reach', number_format($totals['reach']), 'target', 'text-emerald-600 dark:text-emerald-400'],
        ['Total Save', number_format($totals['saves']), 'bookmark', 'text-brand-600 dark:text-brand-300'],
        ['Total Share', number_format($totals['shares']), 'share', 'text-pink-600 dark:text-pink-400'],
        ['Total Interaksi', number_format($totals['interactions']), 'zap', 'text-violet-600 dark:text-violet-400'],
        ['Rata² Interaksi', number_format($totals['avg_interactions']), 'activity', 'text-slate-500 dark:text-slate-400'],
        ['Engagement', $er !== null ? $er.'%' : '—', 'sparkles', 'text-emerald-600 dark:text-emerald-400'],
    ];
@endphp

<div {{ $attributes->merge(['class' => 'card p-5']) }}>
    <div class="grid grid-cols-2 gap-x-4 gap-y-5 sm:grid-cols-3 lg:grid-cols-6">
        @foreach ($items as [$label, $value, $icon, $color])
            <div class="flex items-center gap-3">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-slate-100 {{ $color }} dark:bg-white/5">
                    <x-icon :name="$icon" class="h-4 w-4"/>
                </span>
                <div class="min-w-0">
                    <p class="truncate text-lg font-extrabold leading-tight text-slate-800 dark:text-white">{{ $value }}</p>
                    <p class="truncate text-[11px] text-slate-400">{{ $label }}</p>
                </div>
            </div>
        @endforeach
    </div>
</div>
