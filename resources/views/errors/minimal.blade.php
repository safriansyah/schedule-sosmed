{{--
    The page behind every error Laravel shows (401, 403, 404, 419, 429, 500,
    503 …): its stock views all extend `errors::minimal`, and this file takes
    that name's place.

    Deliberately self-contained — no @vite, no Alpine. An error page is
    exactly when the build or the app may be what is broken, and it must still
    render. The light/dark switch therefore uses a few lines of plain JS on
    the same localStorage key ("theme") as the rest of the app, so the choice
    carries over in both directions.
--}}
@php
    $code = trim($__env->yieldContent('code'));

    [$heading, $explain] = match ($code) {
        '401' => ['Perlu masuk terlebih dahulu', 'Silakan login untuk membuka halaman ini.'],
        '402' => ['Pembayaran diperlukan', 'Halaman ini memerlukan pembayaran.'],
        '403' => ['Akses ditolak', 'Akun Anda tidak memiliki izin untuk membuka halaman ini.'],
        '404' => ['Halaman tidak ditemukan', 'Alamat yang Anda buka tidak ada atau sudah dipindahkan.'],
        '419' => ['Sesi sudah berakhir', 'Halaman terlalu lama dibiarkan. Muat ulang halaman, lalu coba lagi.'],
        '429' => ['Terlalu banyak permintaan', 'Tunggu sebentar, lalu coba lagi.'],
        '500' => ['Terjadi kesalahan di server', 'Maaf, ada yang tidak beres. Coba lagi beberapa saat lagi.'],
        '503' => ['Sedang dalam pemeliharaan', 'Aplikasi sedang diperbarui. Silakan kembali beberapa saat lagi.'],
        default => [trim($__env->yieldContent('message')) ?: 'Terjadi kesalahan', ''],
    };
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $code }} · {{ $heading }}</title>

    <script>
        (function () {
            var stored = null;
            try { stored = localStorage.getItem('theme'); } catch (e) {}
            var dark = stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.classList.toggle('dark', dark);
        })();
    </script>

    <style>
        :root {
            --bg: #f8fafc; --card: #ffffff; --border: #e2e8f0;
            --text: #1e293b; --muted: #64748b; --brand: #7c3aed; --brand-soft: rgba(124, 58, 237, .1);
        }
        html.dark {
            --bg: #08080f; --card: #0d0d18; --border: rgba(255, 255, 255, .08);
            --text: #f1f5f9; --muted: #94a3b8; --brand: #a78bfa; --brand-soft: rgba(167, 139, 250, .12);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px;
            background: var(--bg); color: var(--text);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            -webkit-font-smoothing: antialiased; overflow-wrap: break-word;
        }
        .card {
            width: 100%; max-width: 440px; text-align: center; padding: 40px 28px;
            background: var(--card); border: 1px solid var(--border); border-radius: 24px;
            box-shadow: 0 10px 30px -12px rgba(15, 23, 42, .15);
        }
        .code {
            display: inline-block; padding: 6px 14px; border-radius: 999px;
            background: var(--brand-soft); color: var(--brand);
            font: 700 13px/1 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; letter-spacing: .1em;
        }
        h1 { margin: 18px 0 8px; font-size: 22px; line-height: 1.3; font-weight: 800; letter-spacing: -.01em; }
        p { margin: 0; color: var(--muted); font-size: 15px; line-height: 1.6; }
        .actions { margin-top: 28px; display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; min-height: 42px; padding: 0 18px;
            border-radius: 12px; font: 600 14px/1 inherit; text-decoration: none; cursor: pointer;
            border: 1px solid var(--border); background: transparent; color: var(--text);
        }
        .btn:hover { background: var(--brand-soft); }
        .btn-primary { background: var(--brand); border-color: var(--brand); color: #fff; }
        .btn-primary:hover { background: var(--brand); filter: brightness(1.08); }
        .toggle {
            position: fixed; top: 16px; right: 16px; width: 40px; height: 40px; display: grid; place-items: center;
            border-radius: 12px; border: 1px solid var(--border); background: var(--card); color: var(--muted); cursor: pointer;
        }
        .toggle:hover { color: var(--text); }
        .toggle svg { width: 20px; height: 20px; }
        html.dark .icon-moon, html:not(.dark) .icon-sun { display: none; }
    </style>
</head>
<body>
    <button type="button" class="toggle" id="theme-toggle" aria-label="Ganti tema" title="Ganti tema">
        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>
        </svg>
        <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9z"/>
        </svg>
    </button>

    <main class="card">
        <span class="code">{{ $code ?: 'ERROR' }}</span>
        <h1>{{ $heading }}</h1>
        @if ($explain)
            <p>{{ $explain }}</p>
        @endif

        <div class="actions">
            <a href="javascript:history.back()" class="btn">Kembali</a>
            <a href="{{ url('/') }}" class="btn btn-primary">Ke Beranda</a>
        </div>
    </main>

    <script>
        document.getElementById('theme-toggle').addEventListener('click', function () {
            var dark = !document.documentElement.classList.contains('dark');
            document.documentElement.classList.toggle('dark', dark);
            try { localStorage.setItem('theme', dark ? 'dark' : 'light'); } catch (e) {}
        });
    </script>
</body>
</html>
