@props([
    'type' => 'lines',   // lines | stats | table | chart
    'rows' => 4,
])

@switch($type)
    @case('stats')
        <div {{ $attributes->merge(['class' => 'grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4']) }}>
            @for ($i = 0; $i < $rows; $i++)
                <div class="card p-5">
                    <div class="flex items-start justify-between">
                        <div class="flex-1 space-y-3">
                            <div class="skeleton h-3 w-24"></div>
                            <div class="skeleton h-8 w-20"></div>
                        </div>
                        <div class="skeleton h-11 w-11 rounded-2xl"></div>
                    </div>
                </div>
            @endfor
        </div>
        @break

    @case('table')
        <div {{ $attributes->merge(['class' => 'card divide-y divide-slate-100 dark:divide-white/5']) }}>
            @for ($i = 0; $i < $rows; $i++)
                <div class="flex items-center gap-4 p-4">
                    <div class="skeleton h-12 w-12 rounded-xl"></div>
                    <div class="flex-1 space-y-2">
                        <div class="skeleton h-3 w-1/3"></div>
                        <div class="skeleton h-3 w-1/2"></div>
                    </div>
                    <div class="skeleton h-6 w-20 rounded-full"></div>
                </div>
            @endfor
        </div>
        @break

    @case('chart')
        <div {{ $attributes->merge(['class' => 'card p-5']) }}>
            <div class="skeleton mb-4 h-3 w-40"></div>
            <div class="skeleton h-72 w-full rounded-xl"></div>
        </div>
        @break

    @default
        <div {{ $attributes->merge(['class' => 'space-y-2']) }}>
            @for ($i = 0; $i < $rows; $i++)
                <div class="skeleton h-3" style="width: {{ random_int(60, 100) }}%"></div>
            @endfor
        </div>
@endswitch
