@props([
    'name',                 // x-data flag name used by the trigger, e.g. $dispatch('open-modal', 'confirm')
    'title' => null,
    'maxWidth' => 'lg',     // sm | md | lg | xl | 2xl
])

@php
    $widths = [
        'sm' => 'max-w-sm', 'md' => 'max-w-md', 'lg' => 'max-w-lg',
        'xl' => 'max-w-xl', '2xl' => 'max-w-2xl',
    ];
@endphp

<div
    x-data="{ open: false }"
    x-on:open-modal.window="if ($event.detail === '{{ $name }}') open = true"
    x-on:close-modal.window="if ($event.detail === '{{ $name }}') open = false"
    x-on:keydown.escape.window="open = false"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-[90] flex items-center justify-center p-4"
>
    {{-- Backdrop --}}
    <div x-show="open" x-transition.opacity
         @click="open = false"
         class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm"></div>

    {{-- Panel --}}
    <div x-show="open"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95 translate-y-2"
         x-transition:enter-end="opacity-100 scale-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         class="card relative w-full {{ $widths[$maxWidth] ?? $widths['lg'] }} p-6 shadow-2xl">

        @if ($title)
            <div class="mb-4 flex items-start justify-between gap-4">
                <p class="text-base font-semibold text-slate-800 dark:text-white">{{ $title }}</p>
                <button @click="open = false" class="text-slate-400 transition hover:text-slate-600 dark:hover:text-slate-200">
                    <x-icon name="x" class="h-5 w-5"/>
                </button>
            </div>
        @endif

        {{ $slot }}

        @isset($footer)
            <div class="mt-6 flex justify-end gap-2">{{ $footer }}</div>
        @endisset
    </div>
</div>
