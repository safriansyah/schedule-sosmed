@props([
    'label' => '',
    'value' => '0',
    'icon' => 'hash',
    'tone' => 'brand',      // brand | emerald | amber | cyan | rose | pink | slate | sky | indigo | violet
    'hint' => null,
    'href' => null,         // makes the whole card a link
    'current' => null,      // pair with $previous to render a delta badge
    'previous' => null,
    'deltaSuffix' => null,
    'tint' => false,        // wash the whole card in the tone colour
])

@php
    $tones = [
        'brand'   => 'from-brand-500/15 to-brand-500/0 text-brand-600 dark:text-brand-300',
        'emerald' => 'from-emerald-500/15 to-emerald-500/0 text-emerald-600 dark:text-emerald-400',
        'amber'   => 'from-amber-500/15 to-amber-500/0 text-amber-600 dark:text-amber-400',
        'cyan'    => 'from-cyan-500/15 to-cyan-500/0 text-cyan-600 dark:text-cyan-400',
        'rose'    => 'from-rose-500/15 to-rose-500/0 text-rose-600 dark:text-rose-400',
        'pink'    => 'from-pink-500/15 to-pink-500/0 text-pink-600 dark:text-pink-400',
        'slate'   => 'from-slate-400/15 to-slate-400/0 text-slate-500 dark:text-slate-400',
        'sky'     => 'from-sky-500/15 to-sky-500/0 text-sky-600 dark:text-sky-400',
        'indigo'  => 'from-indigo-500/15 to-indigo-500/0 text-indigo-600 dark:text-indigo-400',
        'violet'  => 'from-violet-500/15 to-violet-500/0 text-violet-600 dark:text-violet-400',
    ];

    // Full-card wash: coloured background + border for the whole tile.
    $tints = [
        'brand'   => '!border-brand-500/30 !bg-brand-500/[0.06] dark:!bg-brand-500/[0.12]',
        'emerald' => '!border-emerald-500/30 !bg-emerald-500/[0.06] dark:!bg-emerald-500/[0.12]',
        'amber'   => '!border-amber-500/30 !bg-amber-500/[0.06] dark:!bg-amber-500/[0.12]',
        'cyan'    => '!border-cyan-500/30 !bg-cyan-500/[0.06] dark:!bg-cyan-500/[0.12]',
        'rose'    => '!border-rose-500/30 !bg-rose-500/[0.06] dark:!bg-rose-500/[0.12]',
        'pink'    => '!border-pink-500/30 !bg-pink-500/[0.06] dark:!bg-pink-500/[0.12]',
        'slate'   => '!border-slate-500/30 !bg-slate-500/[0.06] dark:!bg-slate-500/[0.12]',
        'sky'     => '!border-sky-500/30 !bg-sky-500/[0.06] dark:!bg-sky-500/[0.12]',
        'indigo'  => '!border-indigo-500/30 !bg-indigo-500/[0.06] dark:!bg-indigo-500/[0.12]',
        'violet'  => '!border-violet-500/30 !bg-violet-500/[0.06] dark:!bg-violet-500/[0.12]',
    ];

    $toneClass = $tones[$tone] ?? $tones['brand'];
    $tintClass = $tint ? ($tints[$tone] ?? '') : '';
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif
    class="card-glow group relative block overflow-hidden p-3.5 sm:p-5 {{ $tintClass }}">

    <div class="pointer-events-none absolute -right-6 -top-6 h-28 w-28 rounded-full bg-gradient-to-br {{ $toneClass }} opacity-70 blur-2xl"></div>

    <div class="relative flex items-start justify-between gap-2 sm:gap-3">
        <div class="min-w-0">
            {{-- Two lines on a phone, where two cards share a row and a long label
                 would otherwise be cut to "MENUNGG…". --}}
            <p class="line-clamp-2 text-[11px] font-medium uppercase leading-tight tracking-wide text-slate-400 [overflow-wrap:anywhere] sm:line-clamp-1 sm:text-xs sm:leading-normal">{{ $label }}</p>

            <p class="mt-1.5 text-2xl font-extrabold tracking-tight text-slate-800 sm:mt-2 sm:text-3xl dark:text-white">
                {{ $value }}
            </p>

            @if ($hint)
                <p class="mt-1 line-clamp-2 text-[11px] leading-tight text-slate-400 sm:line-clamp-1 sm:text-xs sm:leading-normal">{{ $hint }}</p>
            @endif
        </div>

        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-xl bg-gradient-to-br sm:h-11 sm:w-11 sm:rounded-2xl {{ $toneClass }}">
            <x-icon :name="$icon" class="h-4 w-4 sm:h-5 sm:w-5"/>
        </span>
    </div>

    @if (! is_null($current) && ! is_null($previous))
        <x-delta :current="$current" :previous="$previous" :suffix="$deltaSuffix" class="relative mt-3"/>
    @endif
</{{ $tag }}>
