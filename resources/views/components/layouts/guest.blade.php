<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Masuk' }} · {{ config('app.name') }}</title>

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
</head>
<body class="h-full" x-data x-cloak>

    <div class="min-h-full lg:grid lg:grid-cols-2">

        {{-- Brand panel — hidden on small screens --}}
        <div class="relative hidden overflow-hidden bg-gradient-to-br from-brand-700 via-brand-600 to-brand-800 lg:flex lg:flex-col lg:justify-between lg:p-12">
            {{-- Decorative glow + grid --}}
            <div class="pointer-events-none absolute -right-24 -top-24 h-96 w-96 rounded-full bg-white/10 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-32 -left-24 h-96 w-96 rounded-full bg-accent-400/20 blur-3xl"></div>
            <div class="pointer-events-none absolute inset-0 opacity-[0.07]"
                 style="background-image: linear-gradient(#fff 1px, transparent 1px), linear-gradient(90deg, #fff 1px, transparent 1px); background-size: 40px 40px;"></div>

            <div class="relative flex items-center gap-3 text-white">
                <div class="grid h-11 w-11 place-items-center rounded-xl bg-white/15 backdrop-blur">
                    <x-icon name="chart" class="h-6 w-6"/>
                </div>
                <span class="text-lg font-bold">{{ config('app.name') }}</span>
            </div>

            <div class="relative text-white">
                <h2 class="max-w-md text-3xl font-extrabold leading-tight">
                    Pantau, jadwalkan, dan analisis konten media sosial Anda.
                </h2>
                <p class="mt-4 max-w-md text-white/70">
                    Satu tempat untuk alur kerja tim, penjadwalan Instagram, dan monitoring performa secara menyeluruh.
                </p>

                <div class="mt-8 grid max-w-md grid-cols-1 gap-3 sm:grid-cols-2">
                    @foreach ([
                        ['calendar', 'Penjadwalan Konten'],
                        ['check-circle', 'Alur Approval'],
                        ['chart', 'Analytics Lengkap'],
                        ['monitor', 'Monitoring Real Data'],
                    ] as [$icon, $label])
                        <div class="flex items-center gap-2.5 rounded-xl bg-white/10 px-3.5 py-3 backdrop-blur">
                            <x-icon :name="$icon" class="h-4 w-4 text-white/80"/>
                            <span class="text-sm font-medium text-white/90">{{ $label }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <p class="relative text-xs text-white/50">© {{ date('Y') }} {{ config('app.name') }}</p>
        </div>

        {{-- Form panel --}}
        <div class="relative flex min-h-full items-center justify-center overflow-hidden px-4 py-12">
            {{-- Ambient glow for small screens --}}
            <div class="pointer-events-none absolute -left-32 -top-32 h-96 w-96 rounded-full bg-brand-500/20 blur-3xl lg:hidden"></div>

            <div class="relative w-full max-w-md animate-[rise_0.5s_ease]">
                {{-- Brand (mobile only) --}}
                <div class="mb-8 flex flex-col items-center text-center lg:hidden">
                    <div class="grid h-14 w-14 place-items-center rounded-2xl bg-gradient-to-br from-brand-600 to-brand-500 text-white shadow-lg">
                        <x-icon name="chart" class="h-7 w-7"/>
                    </div>
                    <h1 class="mt-4 text-2xl font-extrabold tracking-tight text-slate-800 dark:text-white">
                        {{ config('app.name') }}
                    </h1>
                </div>

                {{ $slot }}
            </div>
        </div>
    </div>

    @include('partials.toasts')
</body>
</html>
