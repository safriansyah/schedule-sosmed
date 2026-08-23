<header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200/70 bg-white/80 px-4 backdrop-blur-xl dark:border-white/[0.06] dark:bg-ink-950/80 sm:px-6 lg:px-8">

    {{-- Mobile drawer toggle --}}
    <button @click="$store.ui.sidebarOpen = true"
            class="btn-ghost -ml-2 !px-2 lg:hidden" aria-label="Buka menu">
        <x-icon name="menu" class="h-5 w-5"/>
    </button>

    {{-- Desktop compact toggle --}}
    <button @click="$store.ui.toggleCollapsed()"
            class="btn-ghost -ml-2 hidden !px-2 lg:inline-flex" aria-label="Ciutkan sidebar">
        <x-icon name="menu" class="h-5 w-5"/>
    </button>

    <div class="min-w-0">
        <p class="truncate text-sm font-semibold text-slate-700 dark:text-slate-200">
            {{ $title ?? 'Dashboard' }}
        </p>
    </div>

    <div class="ml-auto flex items-center gap-2">
        {{-- Theme toggle --}}
        <button @click="$store.theme.toggle()"
                class="btn-ghost relative !px-2.5" aria-label="Ganti tema">
            <x-icon name="sun" class="h-5 w-5" x-show="$store.theme.dark"/>
            <x-icon name="moon" class="h-5 w-5" x-show="!$store.theme.dark"/>
        </button>

        {{-- Profile menu --}}
        <div x-data="{ open: false }" class="relative">
            <button @click="open = !open" @click.outside="open = false"
                    class="flex items-center gap-2 rounded-xl px-2 py-1.5 transition hover:bg-slate-100 dark:hover:bg-white/5">
                <span class="avatar h-8 w-8 text-sm">{{ auth()->user()->initial() }}</span>
                <x-icon name="chevron-down" class="hidden h-4 w-4 text-slate-400 sm:block"/>
            </button>

            <div x-show="open" x-cloak
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0 -translate-y-2"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 class="card absolute right-0 mt-2 w-60 p-2 shadow-xl">
                <div class="px-3 py-2">
                    <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ auth()->user()->name }}</p>
                    <p class="truncate text-xs text-slate-400">{{ auth()->user()->email }}</p>
                </div>

                <div class="my-1 h-px bg-slate-100 dark:bg-white/5"></div>

                @if (Route::has('settings.edit'))
                    <a href="{{ route('settings.edit') }}" class="nav-link">
                        <x-icon name="settings" class="h-4 w-4"/> Pengaturan
                    </a>
                @endif

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="nav-link w-full text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-500/10">
                        <x-icon name="logout" class="h-4 w-4"/> Keluar
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
