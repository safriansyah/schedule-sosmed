{{--
    Layout for pages anyone may open without logging in (Buku Tamu / Antrian).

    Like public/tasks, deliberately NOT x-layouts.app: that one renders the
    sidebar, the notification bell and the signed-in user. A public page gets
    nothing but its own content, the brand, and the toast stack.

    wide: the monitor uses the whole screen; forms stay narrow.
--}}
@props(['title', 'wide' => false, 'themeToggle' => true])
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · {{ $branding->name() }}</title>

    @if ($branding->faviconUrl())
        <link rel="icon" href="{{ $branding->faviconUrl() }}">
    @endif

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
<body {{ $attributes->merge(['class' => 'min-h-full bg-slate-50 dark:bg-ink-950']) }} x-data>

    {{-- Light / dark. The monitor turns this off: it has its own in the header. --}}
    @if ($themeToggle)
        <x-theme-toggle class="fixed right-4 top-4 z-40"/>
    @endif

    <div class="{{ $wide ? 'px-4 py-6 sm:px-8 lg:px-10' : 'mx-auto max-w-2xl px-4 py-8 sm:px-6 sm:py-12' }}">
        {{ $slot }}
    </div>

    @include('partials.toasts')
    @stack('scripts')
</body>
</html>
