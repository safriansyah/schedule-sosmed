@props(['title' => 'Keputusan', 'action'])

{{--
    Decision modal for the approval / verification queues. Relies on an Alpine
    `open` boolean in the surrounding card's x-data. The submit buttons live in
    the <x-slot:actions> and carry the decision via name="action".
--}}
<div x-show="open" x-cloak class="fixed inset-0 z-[90] flex items-center justify-center p-4">
    <div x-show="open" x-transition.opacity @click="open = false"
         class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm"></div>

    <form method="POST" action="{{ $action }}"
          x-show="open"
          x-transition:enter="transition ease-out duration-200"
          x-transition:enter-start="opacity-0 scale-95 translate-y-2"
          x-transition:enter-end="opacity-100 scale-100 translate-y-0"
          class="card relative flex max-h-[90vh] w-full max-w-lg flex-col p-6 shadow-2xl">
        @csrf

        <div class="mb-4 flex items-start justify-between gap-4">
            <p class="truncate text-base font-semibold text-slate-800 dark:text-white">{{ $title }}</p>
            <button type="button" @click="open = false"
                    class="shrink-0 text-slate-400 transition hover:text-slate-600 dark:hover:text-slate-200">
                <x-icon name="x" class="h-5 w-5"/>
            </button>
        </div>

        <div class="-mx-1 flex-1 overflow-y-auto px-1">
            {{ $slot }}
        </div>

        <div class="mt-6 flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-4 dark:border-white/5">
            <button type="button" @click="open = false" class="btn-outline btn-sm">Batal</button>
            {{ $actions }}
        </div>
    </form>
</div>
