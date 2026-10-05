{{--
    The public task board.

    Deliberately NOT built on x-layouts.app: that layout renders the sidebar,
    the notification bell and the signed-in user, all of which need an
    authenticated user and none of which belong on a public page. This is a
    standalone document with nothing but the whitelisted task fields on it.
--}}
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Jadwal Kegiatan · {{ config('app.name') }}</title>

    {{-- Public page: no personal data here, and no reason for search engines
         to index an internal work plan either. --}}
    <meta name="robots" content="noindex, nofollow">

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
<body class="min-h-full bg-slate-50 dark:bg-ink-950" x-data>

    <x-theme-toggle class="fixed right-4 top-4 z-40"/>

    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
        <header class="mb-10 text-center">
            <div class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-2xl bg-gradient-to-br from-brand-600 to-brand-500 text-white shadow-lg">
                <x-icon name="calendar" class="h-7 w-7"/>
            </div>

            <h1 class="text-2xl font-extrabold tracking-tight text-slate-800 dark:text-white sm:text-3xl">
                Jadwal Kegiatan
            </h1>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                Agenda kegiatan {{ config('app.name') }} yang dipublikasikan.
            </p>
        </header>

        @if ($count === 0)
            <div class="card p-12 text-center">
                <div class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-slate-100 text-slate-400 dark:bg-white/5">
                    <x-icon name="calendar" class="h-7 w-7"/>
                </div>
                <p class="mt-4 text-base font-semibold text-slate-700 dark:text-slate-200">Belum ada kegiatan</p>
                <p class="mt-1 text-sm text-slate-400">Agenda akan tampil di sini setelah dipublikasikan.</p>
            </div>
        @else
            @foreach ($groups as $month => $tasks)
                <section class="mb-8">
                    <h2 class="mb-3 text-xs font-bold uppercase tracking-wider text-slate-400">{{ $month }}</h2>

                    <div class="space-y-3">
                        @foreach ($tasks as $task)
                            <article class="card p-5">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0 flex-1">
                                        <h3 class="font-bold text-slate-800 dark:text-white">
                                            {{ $task['title'] }}
                                        </h3>

                                        <p class="mt-1 flex items-center gap-1.5 text-sm text-slate-500 dark:text-slate-400">
                                            <x-icon name="calendar" class="h-3.5 w-3.5 shrink-0"/>
                                            @if ($task['start_date']->isSameDay($task['due_date']))
                                                {{ $task['start_date']->translatedFormat('d F Y') }}
                                            @else
                                                {{ $task['start_date']->translatedFormat('d F') }}
                                                – {{ $task['due_date']->translatedFormat('d F Y') }}
                                            @endif
                                        </p>
                                    </div>

                                    <span class="{{ $task['status']->badge() }} shrink-0">
                                        <x-icon :name="$task['status']->icon()" class="h-3 w-3"/>
                                        {{ $task['status']->label() }}
                                    </span>
                                </div>

                                @if ($task['description'])
                                    <p class="mt-3 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">
                                        {{ $task['description'] }}
                                    </p>
                                @endif

                                @if ($task['progress'] > 0)
                                    <div class="mt-4">
                                        <div class="mb-1 flex items-center justify-between text-[11px] text-slate-400">
                                            <span>Progress</span>
                                            <span>{{ $task['progress'] }}%</span>
                                        </div>
                                        <div class="h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/5">
                                            <div class="h-full rounded-full bg-brand-500" style="width: {{ $task['progress'] }}%"></div>
                                        </div>
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </section>
            @endforeach
        @endif

        <footer class="mt-12 text-center text-xs text-slate-400">
            {{ config('app.name') }} &middot; {{ date('Y') }}
        </footer>
    </div>

</body>
</html>
