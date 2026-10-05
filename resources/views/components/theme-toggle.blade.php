{{-- Light / dark switch. One component for every page, so the button looks
     and behaves the same everywhere. Drives the app-wide $store.theme, which
     remembers the choice in this browser (see resources/js/app.js).

     variant: "ghost" (topbar), "boxed" (bordered square, for pages without a
     header bar). --}}
@props(['variant' => 'boxed'])

<button type="button" @click="$store.theme.toggle()"
        :aria-label="$store.theme.dark ? 'Ganti ke mode terang' : 'Ganti ke mode gelap'"
        :title="$store.theme.dark ? 'Mode terang' : 'Mode gelap'"
        aria-label="Ganti tema"
        {{ $attributes->class([
            'btn-ghost relative !px-2.5' => $variant === 'ghost',
            'grid h-10 w-10 place-items-center rounded-xl border border-slate-200 bg-white/80 text-slate-600 shadow-sm backdrop-blur transition hover:bg-white hover:text-slate-900 dark:border-white/10 dark:bg-white/5 dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-white' => $variant === 'boxed',
        ]) }}>
    <x-icon name="sun" class="h-5 w-5" x-show="$store.theme.dark" x-cloak/>
    <x-icon name="moon" class="h-5 w-5" x-show="! $store.theme.dark"/>
</button>
