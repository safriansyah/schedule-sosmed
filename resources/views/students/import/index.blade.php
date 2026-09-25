<x-layouts.app title="Import Data Mahasiswa">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Import Data Mahasiswa</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Unggah Excel atau CSV. Data masuk sebagai <strong>belum assigned</strong> — pembagian tetap keputusan admin.
            </p>
        </div>

        <a href="{{ route('students.import.template') }}" class="btn-outline">
            <x-icon name="download" class="h-4 w-4"/> Unduh Template
        </a>
    </x-slot:header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            <form method="POST" action="{{ route('students.import.store') }}" enctype="multipart/form-data" class="card p-5">
                @csrf

                <h2 class="mb-4 text-base font-bold text-slate-800 dark:text-white">Unggah Berkas</h2>

                <div>
                    <label for="file" class="label">Berkas .xlsx, .xls, atau .csv (maks 50 MB)</label>
                    <input id="file" name="file" type="file" accept=".xlsx,.xls,.csv,.txt" required
                           class="input file:mr-3 file:rounded-lg file:border-0 file:bg-brand-600 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white">
                    @error('file') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <div class="mt-4 rounded-xl bg-slate-50 p-4 text-xs text-slate-500 dark:bg-white/[0.03] dark:text-slate-400">
                    <p class="font-semibold text-slate-600 dark:text-slate-300">Yang terjadi setelah unggah:</p>
                    <ol class="mt-2 list-decimal space-y-1 pl-4">
                        <li>Berkas dibaca, belum ada data yang disimpan.</li>
                        <li>Anda memeriksa hasil pemetaan kolom.</li>
                        <li>Setelah dikonfirmasi, barulah data diimport.</li>
                    </ol>
                </div>

                <div class="mt-4 flex justify-end">
                    <button class="btn-primary"><x-icon name="upload" class="h-4 w-4"/> Unggah & Periksa</button>
                </div>
            </form>

            <div class="card p-5">
                <h2 class="mb-4 text-base font-bold text-slate-800 dark:text-white">Riwayat Import</h2>

                @if ($imports->isEmpty())
                    <p class="text-sm text-slate-400">Belum ada import.</p>
                @else
                    <div class="space-y-2">
                        @foreach ($imports as $import)
                            <a href="{{ route('students.import.show', $import) }}"
                               class="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 p-3 transition hover:bg-slate-50 dark:border-white/10 dark:hover:bg-white/[0.02]">
                                <x-status-badge :status="$import->status"/>

                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        {{ $import->original_name }}
                                    </p>
                                    <p class="text-[11px] text-slate-400">
                                        {{ $import->created_at->translatedFormat('d M Y H:i') }}
                                        · {{ $import->creator?->name ?? 'Sistem' }}
                                    </p>
                                </div>

                                @if ($import->status === \App\Models\StudentImport::STATUS_COMPLETED)
                                    <span class="text-xs text-slate-400">
                                        <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ number_format($import->successCount()) }}</span> berhasil
                                        @if ($import->failed_count)
                                            · <span class="font-semibold text-rose-500">{{ number_format($import->failed_count) }}</span> gagal
                                        @endif
                                    </span>
                                @endif
                            </a>
                        @endforeach
                    </div>

                    <div class="mt-4">{{ $imports->links() }}</div>
                @endif
            </div>
        </div>

        {{-- The live mapping, so the admin can see exactly which spreadsheet
             headings are understood before uploading anything. --}}
        <div class="card p-5">
            <h2 class="mb-1 text-base font-bold text-slate-800 dark:text-white">Kolom yang Dikenali</h2>
            <p class="mb-4 text-xs text-slate-400">
                Judul kolom dicocokkan tanpa memedulikan huruf besar/kecil dan tanda baca.
                Kolom lain tetap disimpan sebagai data tambahan.
            </p>

            <dl class="space-y-3 text-xs">
                @foreach ($mapping as $field => $aliases)
                    <div>
                        <dt class="font-mono font-semibold text-slate-700 dark:text-slate-200">{{ $field }}</dt>
                        <dd class="mt-1 flex flex-wrap gap-1">
                            @foreach (array_slice($aliases, 0, 5) as $alias)
                                <span class="badge-slate">{{ $alias }}</span>
                            @endforeach
                            @if (count($aliases) > 5)
                                <span class="badge-slate">+{{ count($aliases) - 5 }}</span>
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>

            <p class="mt-4 border-t border-slate-100 pt-3 text-[11px] text-slate-400 dark:border-white/5">
                Berkas Excel asli belum tersedia. Ketika nanti diberikan, penyesuaian cukup dilakukan
                di <span class="font-mono">config/students.php</span> tanpa mengubah kode.
            </p>
        </div>
    </div>
</x-layouts.app>
