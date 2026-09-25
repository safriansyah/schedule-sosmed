@props([
    'title' => '',
    'rows' => [],           // each row needs ->name and ->total
    'total' => 0,           // the denominator for the share bar
    'empty' => 'Belum ada data.',
    'icon' => 'chart',
])

{{--
    A "how does this split up" table, used by all three reports.

    One component rather than five near-identical blocks: the share bar and the
    percentage rounding are the fiddly parts, and having them in one place is
    what stops two reports on the same screen disagreeing about what 12,5%
    looks like.

    The bar is drawn against the largest row, not against the total, so a long
    tail of small values stays readable — but the PERCENTAGE is always of the
    total, because that is the number people quote.
--}}
@php
    $rows = collect($rows);
    $max = max(1, (int) $rows->max('total'));
    $total = max(0, (int) $total);
@endphp

<div class="card overflow-hidden">
    <div class="flex items-center justify-between border-b border-slate-200 p-4 dark:border-white/5">
        <h3 class="text-sm font-bold text-slate-800 dark:text-white">{{ $title }}</h3>
        <span class="text-[11px] text-slate-400">{{ number_format($rows->count()) }} baris</span>
    </div>

    @if ($rows->isEmpty())
        <x-empty-state :icon="$icon" title="Belum ada data" :description="$empty"/>
    @else
        <div class="divide-y divide-slate-100 dark:divide-white/5">
            @foreach ($rows as $row)
                @php
                    $value = (int) $row->total;
                    $share = $total > 0 ? round($value / $total * 100, 1) : 0;
                @endphp

                <div class="px-4 py-2.5">
                    <div class="flex items-baseline justify-between gap-3 text-sm">
                        <span class="min-w-0 flex-1 truncate text-slate-600 dark:text-slate-300">{{ $row->name }}</span>
                        <span class="shrink-0 font-bold text-slate-700 dark:text-slate-200">{{ number_format($value) }}</span>
                        <span class="w-14 shrink-0 text-right text-[11px] text-slate-400">{{ $share }}%</span>
                    </div>

                    <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/5">
                        <div class="h-full rounded-full bg-brand-500/70"
                             style="width: {{ round($value / $max * 100, 2) }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
