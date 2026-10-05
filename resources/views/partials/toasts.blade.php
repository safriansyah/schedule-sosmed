{{-- Toast stack — driven by Alpine store('toasts'); seeded from session flash --}}
@php
    /**
     * Two flash conventions exist in this codebase and both must work.
     *
     *   session('toast') => ['message' => …, 'type' => …]   (the original)
     *   session('success' | 'info' | 'warning' | 'error')   (plain string)
     *
     * Reading only the first meant every ->with('success', …) — three dozen of
     * them — flashed into the void: the action succeeded and the user was told
     * nothing at all. Rather than rewrite every call site, both are collected
     * here, which also means neither convention can silently break again.
     */
    $flashes = [];

    if ($structured = session('toast')) {
        $flashes[] = [
            'message' => $structured['message'] ?? '',
            'type' => $structured['type'] ?? 'success',
        ];
    }

    foreach (['success', 'info', 'warning', 'error'] as $type) {
        if (filled($message = session($type))) {
            // Guard against a non-string being flashed under these keys.
            $flashes[] = ['message' => is_string($message) ? $message : json_encode($message), 'type' => $type];
        }
    }

    $flashes = array_values(array_filter($flashes, fn ($f) => filled($f['message'])));

    // Validation errors surface as one toast; the per-field messages are
    // already rendered next to their inputs.
    $showErrors = $errors->any() && ! request()->routeIs('login');
@endphp

<div
    x-data
    @if ($flashes || $showErrors)
        x-init="
            @foreach ($flashes as $flash)
                $store.toasts.push(@js($flash['message']), @js($flash['type']));
            @endforeach
            @if ($showErrors) $store.toasts.push(@js($errors->first()), 'error'); @endif
        "
    @endif
    class="fixed right-5 top-5 z-[100] flex w-[min(92vw,22rem)] flex-col gap-3"
    role="status" aria-live="polite"
>
    <template x-for="t in $store.toasts.items" :key="t.id">
        <div
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-x-6"
            x-transition:enter-end="opacity-100 translate-x-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0 translate-x-6"
            class="glass flex items-start gap-3 rounded-2xl p-4 shadow-lg"
            :class="{
                'ring-1 ring-emerald-500/30': t.type === 'success',
                'ring-1 ring-rose-500/30': t.type === 'error',
                'ring-1 ring-brand-500/30': t.type === 'info',
                'ring-1 ring-amber-500/30': t.type === 'warning',
            }"
        >
            <span class="mt-0.5 shrink-0"
                :class="{
                    'text-emerald-500': t.type === 'success',
                    'text-rose-500': t.type === 'error',
                    'text-brand-500': t.type === 'info',
                    'text-amber-500': t.type === 'warning',
                }">
                <template x-if="t.type === 'success'"><x-icon name="check" class="h-5 w-5"/></template>
                <template x-if="t.type === 'error'"><x-icon name="alert" class="h-5 w-5"/></template>
                <template x-if="t.type === 'info'"><x-icon name="sparkles" class="h-5 w-5"/></template>
                <template x-if="t.type === 'warning'"><x-icon name="alert" class="h-5 w-5"/></template>
            </span>

            <p class="flex-1 text-sm font-medium text-slate-700 dark:text-slate-200" x-text="t.message"></p>

            <button type="button" @click="$store.toasts.remove(t.id)"
                    class="text-slate-400 transition hover:text-slate-600 dark:hover:text-slate-200">
                <x-icon name="x" class="h-4 w-4"/>
            </button>
        </div>
    </template>
</div>
