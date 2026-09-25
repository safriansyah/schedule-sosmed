@php
    // A hub, not a report. Each module owns its own numbers; this page only
    // says which door to walk through, with one headline per module so the
    // choice is informed rather than blind.
    $cards = [
        [
            'route' => 'reports.tickets',
            'title' => 'Laporan Ticketing',
            'blurb' => 'Open / Pending / Closed, per sumber, kategori, operator, dan wilayah untuk tiket dari import mahasiswa.',
            'icon' => 'file-text',
            'tone' => 'violet',
            'headline' => number_format($tickets['total']).' tiket',
            'detail' => number_format($tickets['lead']).' bertanda Lead',
        ],
        [
            'route' => 'reports.students',
            'title' => 'Laporan Data Mahasiswa',
            'blurb' => 'Sebaran kondisi, beban tiap operator, wilayah, dan berapa banyak yang sudah punya tiket.',
            'icon' => 'id-card',
            'tone' => 'brand',
            'headline' => number_format($students['total']).' mahasiswa',
            'detail' => number_format($students['unassigned']).' belum assigned',
        ],
        [
            'route' => 'reports.tasks',
            'title' => 'Laporan Task Management',
            'blurb' => 'Status task, beban per PIC, dan hari aktivitas yang benar-benar tercentang di papan rencana.',
            'icon' => 'calendar',
            'tone' => 'cyan',
            'headline' => number_format($tasks['total']).' task',
            'detail' => number_format($tasks['overdue']).' lewat tenggat',
        ],
    ];

    $tones = [
        'violet' => 'text-violet-600 bg-violet-500/10 dark:text-violet-300',
        'brand' => 'text-brand-600 bg-brand-500/10 dark:text-brand-300',
        'cyan' => 'text-cyan-600 bg-cyan-500/10 dark:text-cyan-300',
    ];
@endphp

<x-layouts.app title="Laporan">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Laporan</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Setiap modul punya laporannya sendiri — pilih yang ingin dibaca.
            </p>
        </div>

        @if ($canExport)
            <a href="{{ route('reports.followUps.export', ['format' => 'xlsx']) }}" class="btn-outline">
                <x-icon name="download" class="h-4 w-4"/> Riwayat Follow Up
            </a>
        @endif
    </x-slot:header>

    <div class="grid gap-4 lg:grid-cols-3">
        @foreach ($cards as $card)
            <a href="{{ route($card['route']) }}"
               class="card group p-5 transition hover:-translate-y-0.5 hover:border-brand-500/40 hover:shadow-lg">
                <span class="grid h-11 w-11 place-items-center rounded-2xl {{ $tones[$card['tone']] }}">
                    <x-icon :name="$card['icon']" class="h-5 w-5"/>
                </span>

                <h2 class="mt-3 text-base font-bold text-slate-800 group-hover:text-brand-600 dark:text-white">
                    {{ $card['title'] }}
                </h2>

                <p class="mt-1 text-2xl font-extrabold tracking-tight text-slate-800 dark:text-white">
                    {{ $card['headline'] }}
                </p>
                <p class="text-xs text-slate-400">{{ $card['detail'] }}</p>

                <p class="mt-3 text-xs leading-relaxed text-slate-500 dark:text-slate-400">{{ $card['blurb'] }}</p>

                <span class="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400">
                    Buka laporan <x-icon name="chevron-right" class="h-3.5 w-3.5"/>
                </span>
            </a>
        @endforeach
    </div>

    <div class="card mt-6 p-4">
        <p class="flex items-start gap-2 text-xs text-slate-500 dark:text-slate-400">
            <x-icon name="help-circle" class="mt-0.5 h-4 w-4 shrink-0 text-slate-400"/>
            <span>
                Laporan dipisah karena tidak ada satu orang pun yang membaca ketiganya sekaligus —
                penanganan tiket, data mahasiswa, dan perencanaan task dipegang peran yang berbeda.
                Angka mahasiswa di ketiga halaman berasal dari sumber yang sama, jadi tidak akan berbeda.
            </span>
        </p>
    </div>
</x-layouts.app>
