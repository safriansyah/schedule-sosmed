@props([
    'title' => null,
    'subtitle' => null,
    'height' => 320,
    'options' => [],   // ApexCharts options (series, chart.type, xaxis, …)
])

<div {{ $attributes->merge(['class' => 'card p-5']) }}>
    @if ($title || isset($actions))
        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                @if ($title)
                    <p class="truncate text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $title }}</p>
                @endif
                @if ($subtitle)
                    <p class="truncate text-xs text-slate-400">{{ $subtitle }}</p>
                @endif
            </div>

            @isset($actions)
                <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    <div
        x-data="apexChart(@js(array_replace_recursive(['chart' => ['height' => $height]], $options)))"
        x-init="mount()"
        @destroy.window="destroy()"
    >
        <div x-ref="canvas" style="min-height: {{ $height }}px"></div>
    </div>
</div>
