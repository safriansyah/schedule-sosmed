@props([
    'current' => 0,
    'previous' => 0,
    'suffix' => '',      // e.g. "vs kemarin"
    'invert' => false,   // true when a decrease is the good outcome
])

@php
    $current = (float) $current;
    $previous = (float) $previous;

    // Percentage change; a jump from zero is shown as "baru" instead of ∞.
    $isNew = $previous == 0.0 && $current > 0;
    $percent = $previous == 0.0 ? null : (($current - $previous) / abs($previous)) * 100;

    $up = $percent !== null ? $percent > 0 : $isNew;
    $flat = $percent !== null && abs($percent) < 0.05;

    $good = $invert ? ! $up : $up;
    $tone = $flat ? 'badge-slate' : ($good ? 'badge-green' : 'badge-red');
    $icon = $flat ? 'trend' : ($up ? 'trend' : 'trend-down');
@endphp

<span {{ $attributes->merge(['class' => $tone]) }}>
    <x-icon :name="$icon" class="h-3 w-3"/>

    @if ($isNew)
        Baru
    @elseif ($percent === null)
        0%
    @else
        {{ $percent > 0 ? '+' : '' }}{{ number_format($percent, 1) }}%
    @endif

    @if ($suffix)
        <span class="font-normal opacity-70">{{ $suffix }}</span>
    @endif
</span>
