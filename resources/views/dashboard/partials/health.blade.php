@php
    /**
     * Automation health banner.
     *
     * Deliberately quiet when everything works — a green bar that shouts every
     * day trains people to ignore it, and then it is worthless on the day it
     * turns red. When healthy this collapses to one thin line; when the
     * scheduler is down it takes over the top of the page, because at that
     * point every other number below it is stale.
     */
    $tones = [
        'ok'   => ['border-emerald-500/25 bg-emerald-500/[0.05]', 'text-emerald-600 dark:text-emerald-400', 'check-circle'],
        'warn' => ['border-amber-500/30 bg-amber-500/[0.06]',     'text-amber-600 dark:text-amber-400',     'alert'],
        'down' => ['border-rose-500/40 bg-rose-500/[0.07]',       'text-rose-600 dark:text-rose-400',       'alert'],
    ];

    [$box, $text, $icon] = $tones[$health['state']] ?? $tones['warn'];
@endphp

@if ($health['state'] === 'ok')
    {{-- Healthy: one line, no drama. --}}
    <div class="mb-6 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border {{ $box }} px-4 py-2.5 text-xs">
        <span class="{{ $text }} inline-flex items-center gap-1.5 font-semibold">
            <x-icon :name="$icon" class="h-3.5 w-3.5"/> {{ $health['label'] }}
        </span>
        @foreach ($health['checks'] as $check)
            <span class="text-slate-400">· {{ $check['name'] }}: {{ $check['value'] }}</span>
        @endforeach
    </div>
@else
    <div class="mb-6 rounded-2xl border {{ $box }} p-4">
        <div class="flex items-start gap-3">
            <x-icon :name="$icon" class="{{ $text }} mt-0.5 h-5 w-5 shrink-0"/>

            <div class="min-w-0 flex-1">
                <p class="{{ $text }} font-bold">{{ $health['label'] }}</p>
                <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-300">{{ $health['detail'] }}</p>

                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                    @foreach ($health['checks'] as $check)
                        <div class="flex items-start gap-2 rounded-lg bg-white/60 p-2.5 text-xs dark:bg-white/5">
                            <span @class([
                                'mt-1 h-2 w-2 shrink-0 rounded-full',
                                'bg-emerald-500' => $check['state'] === 'ok',
                                'bg-amber-500' => $check['state'] === 'warn',
                                'bg-rose-500' => $check['state'] === 'down',
                            ])></span>
                            <div class="min-w-0">
                                <p class="font-semibold text-slate-700 dark:text-slate-200">
                                    {{ $check['name'] }} — {{ $check['value'] }}
                                </p>
                                @if ($check['hint'])
                                    {{-- The fix, not just the symptom. --}}
                                    <p class="mt-0.5 text-slate-500 dark:text-slate-400">{{ $check['hint'] }}</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@endif
