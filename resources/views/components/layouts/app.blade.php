<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Dashboard' }} · {{ config('app.name') }}</title>

    {{-- Apply the stored theme before paint so there is no flash of the wrong mode --}}
    <script>
        (function () {
            const stored = localStorage.getItem('theme');
            const dark = stored ? stored === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.classList.toggle('dark', dark);
        })();
    </script>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="h-full" x-data x-cloak>

    <div class="min-h-full lg:flex">

        {{-- Mobile backdrop --}}
        <div x-show="$store.ui.sidebarOpen" x-transition.opacity
             @click="$store.ui.sidebarOpen = false"
             class="fixed inset-0 z-30 bg-slate-900/50 backdrop-blur-sm lg:hidden"></div>

        @include('partials.sidebar')

        {{-- Main column --}}
        <div class="flex min-w-0 flex-1 flex-col">
            @include('partials.topbar')

            <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-[1500px] animate-[fade-in_0.4s_ease]">
                    @isset($header)
                        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            {{ $header }}
                        </div>
                    @endisset

                    {{ $slot }}
                </div>
            </main>

            <footer class="px-6 py-5 text-center text-xs text-slate-400 dark:text-slate-600">
                {{ config('app.name') }} &middot; {{ date('Y') }}
            </footer>
        </div>
    </div>

    @include('partials.toasts')
    @stack('scripts')
</body>
</html>
