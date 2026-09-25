<x-layouts.app title="Periksa Import">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Periksa Sebelum Import</h1>
            <p class="mt-0.5 truncate text-sm text-slate-400">{{ $import->original_name }}</p>
        </div>

        <a href="{{ route('students.import.index') }}" class="btn-outline">
            <x-icon name="x" class="h-4 w-4"/> Batal
        </a>
    </x-slot:header>

    @if ($missing)
        <div class="card mb-6 border-rose-500/30 bg-rose-500/[0.06] p-4">
            <div class="flex items-start gap-3">
                <x-icon name="alert" class="mt-0.5 h-5 w-5 shrink-0 text-rose-500"/>
                <div>
                    <p class="font-semibold text-rose-600 dark:text-rose-400">Kolom wajib tidak ditemukan</p>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                        Berkas ini tidak punya kolom: <strong>{{ implode(', ', $missing) }}</strong>.
                        Perbaiki judul kolom di Excel, lalu unggah ulang.
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- Sheet picker. A real workbook opens on a summary tab: the supplied
         file leads with a six-cell DASHBOARD and keeps its 7.391 rows on the
         second tab. The best-matching tab is chosen automatically, but the
         admin must be able to see and change that. --}}
    @if (count($sheets ?? []) > 1)
        <form method="GET" class="card mb-6 p-4">
            <label for="sheet" class="label">Lembar kerja</label>

            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <select id="sheet" name="sheet" class="input sm:max-w-xs" onchange="this.form.submit()">
                    @foreach ($sheets as $name)
                        <option value="{{ $name }}" @selected($import->sheet === $name)>{{ $name }}</option>
                    @endforeach
                </select>

                <p class="text-xs text-slate-400">
                    Dipilih otomatis berdasarkan kolom yang paling cocok. Ganti bila salah tab.
                </p>
            </div>
        </form>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card p-5">
            <h2 class="mb-4 text-base font-bold text-slate-800 dark:text-white">Pemetaan Kolom</h2>

            @if ($mapping)
                <dl class="space-y-2 text-sm">
                    @foreach ($mapping as $field => $position)
                        <div class="flex items-center gap-2 rounded-lg bg-emerald-500/[0.07] px-3 py-2">
                            <x-icon name="check" class="h-3.5 w-3.5 shrink-0 text-emerald-600 dark:text-emerald-400"/>
                            <dt class="min-w-0 flex-1 truncate text-slate-600 dark:text-slate-300">
                                {{ $headings[$position] ?? '(kolom '.($position + 1).')' }}
                            </dt>
                            <dd class="shrink-0 font-mono text-[11px] text-slate-400">{{ $field }}</dd>
                        </div>
                    @endforeach
                </dl>
            @else
                <p class="text-sm text-slate-400">Tidak ada kolom yang dikenali.</p>
            @endif

            @if ($unmapped)
                <div class="mt-4 border-t border-slate-100 pt-4 dark:border-white/5">
                    <p class="mb-2 text-xs font-semibold text-slate-500 dark:text-slate-400">
                        Disimpan sebagai data tambahan
                    </p>
                    <div class="flex flex-wrap gap-1">
                        @foreach ($unmapped as $heading)
                            <span class="badge-slate">{{ $heading }}</span>
                        @endforeach
                    </div>
                    <p class="mt-2 text-[11px] text-slate-400">
                        Tidak ada data yang dibuang — kolom ini tetap tersimpan dan bisa dipetakan nanti.
                    </p>
                </div>
            @endif
        </div>

        <div class="card overflow-hidden lg:col-span-2">
            <div class="border-b border-slate-200 p-5 dark:border-white/5">
                <h2 class="text-base font-bold text-slate-800 dark:text-white">Contoh Baris</h2>
                <p class="mt-0.5 text-xs text-slate-400">
                    {{ count($rows) }} baris pertama. Periksa apakah isinya jatuh di kolom yang benar.
                </p>
            </div>

            @if ($rows)
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="border-b border-slate-200 bg-slate-50/60 text-left uppercase tracking-wide text-slate-400 dark:border-white/5 dark:bg-white/[0.02]">
                            <tr>
                                @foreach ($headings as $heading)
                                    <th class="whitespace-nowrap px-3 py-2 font-semibold">{{ $heading ?: '—' }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                            @foreach ($rows as $row)
                                <tr>
                                    @foreach ($headings as $index => $heading)
                                        <td class="whitespace-nowrap px-3 py-2 text-slate-600 dark:text-slate-300">
                                            {{ $row[$index] ?? '' }}
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <x-empty-state icon="file-text" title="Tidak ada baris data"
                               description="Berkas hanya berisi header, atau tidak terbaca."/>
            @endif
        </div>
    </div>

    <form method="POST" action="{{ route('students.import.confirm', $import) }}" class="mt-6">
        @csrf
        <input type="hidden" name="sheet" value="{{ $import->sheet }}">

        <div class="card flex flex-wrap items-center justify-between gap-4 p-5">
            <div>
                <p class="font-semibold text-slate-800 dark:text-white">Lanjutkan import?</p>
                <p class="text-xs text-slate-400">
                    Semua mahasiswa baru masuk sebagai <strong>belum assigned</strong>.
                    NIM yang sudah ada akan diperbarui datanya tanpa mengubah operator yang memegangnya.
                </p>
            </div>

            <button class="btn-primary" @disabled((bool) $missing)>
                <x-icon name="check" class="h-4 w-4"/> Import Sekarang
            </button>
        </div>
    </form>
</x-layouts.app>
