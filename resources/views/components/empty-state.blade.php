@props([
    'icon' => 'inbox',
    'title' => 'Belum ada data',
    'description' => null,
])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-14 text-center']) }}>
    <div class="grid h-16 w-16 place-items-center rounded-2xl bg-slate-100 text-slate-400 dark:bg-white/5 dark:text-slate-500">
        <x-icon :name="$icon" class="h-7 w-7"/>
    </div>

    <p class="mt-4 text-base font-semibold text-slate-700 dark:text-slate-200">{{ $title }}</p>

    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-slate-400">{{ $description }}</p>
    @endif

    @if (isset($action))
        <div class="mt-5">{{ $action }}</div>
    @endif
</div>
