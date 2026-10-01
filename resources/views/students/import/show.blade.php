<x-layouts.app title="Hasil Import">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Hasil Import</h1>
            <p class="mt-0.5 truncate text-sm text-slate-400">
                {{ $import->original_name }} · {{ $import->created_at->translatedFormat('d M Y H:i') }}
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            @if ($import->errors)
                <a href="{{ route('students.import.errors', $import) }}" class="btn-outline">
                    <x-icon name="download" class="h-4 w-4"/> Unduh Baris Gagal
                </a>
            @endif

            <a href="{{ route('students.index') }}" class="btn-primary">
                <x-icon name="users" class="h-4 w-4"/> Lihat Data Mahasiswa
            </a>
        </div>
    </x-slot:header>

    {{-- Polls while the queued job runs, then stops. Without this the admin
         would have to refresh by hand to find out whether it finished. --}}
    <div x-data="importStatus(@js(route('students.import.status', $import)), @js($import->isFinished()))"
         x-init="start()">

        <div class="card mb-6 p-5" x-show="!finished" x-cloak>
            <div class="flex items-center gap-3">
                <x-icon name="refresh" class="h-5 w-5 animate-spin text-brand-600"/>
                <div class="min-w-0 flex-1">
                    <p class="font-semibold text-slate-800 dark:text-white">Import sedang berjalan…</p>
                    <p class="text-xs text-slate-400">
                        <span x-text="data.total"></span> baris diproses
                    </p>
                </div>
            </div>

            <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-white/5">
                <div class="h-full rounded-full bg-brand-600 transition-all duration-500"
                     :style="`width: ${data.progress}%`"></div>
            </div>

            {{-- Nothing moved for a while: the queue worker is off or was
                 killed. Offer to run it here rather than spin forever. --}}
            <form method="POST" action="{{ route('students.import.run', $import) }}"
                  x-show="data.stalled" x-cloak
                  x-data="{ busy: false }" @submit="busy = true"
                  class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-500/30 bg-amber-500/[0.06] p-3">
                @csrf
                <p class="min-w-0 flex-1 text-sm text-slate-600 dark:text-slate-300">
                    Antrean latar belakang tidak memproses import ini (queue worker tidak berjalan).
                    Jalankan langsung dari sini — biasanya hanya beberapa detik.
                </p>
                <button class="btn-primary" :disabled="busy">
                    <x-icon name="refresh" class="h-4 w-4"/>
                    <span x-text="busy ? 'Menjalankan…' : 'Jalankan Langsung'"></span>
                </button>
            </form>
        </div>

        <div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-5">
            <x-stat-card label="Total baris" :value="number_format($import->total_rows)" icon="file-text" tone="slate"/>
            <x-stat-card label="Berhasil" :value="number_format($import->successCount())" icon="check-circle" tone="emerald"/>
            <x-stat-card label="Baru" :value="number_format($import->imported_count)" icon="plus" tone="brand"/>
            <x-stat-card label="Diperbarui" :value="number_format($import->updated_count)" icon="refresh" tone="cyan"/>
            <x-stat-card label="Gagal" :value="number_format($import->failed_count)" icon="alert"
                         :tone="$import->failed_count > 0 ? 'rose' : 'slate'"
                         :hint="$import->duplicate_count > 0 ? number_format($import->duplicate_count).' duplikat' : null"/>
        </div>

        @if ($import->error_message)
            <div class="card mb-6 border-rose-500/30 bg-rose-500/[0.06] p-4">
                <div class="flex items-start gap-3">
                    <x-icon name="alert" class="mt-0.5 h-5 w-5 shrink-0 text-rose-500"/>
                    <div>
                        <p class="font-semibold text-rose-600 dark:text-rose-400">Import gagal</p>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ $import->error_message }}</p>
                    </div>
                </div>
            </div>
        @endif

        @if ($import->errors)
            <div class="card overflow-hidden">
                <div class="border-b border-slate-200 p-5 dark:border-white/5">
                    <h2 class="text-base font-bold text-slate-800 dark:text-white">Baris yang Gagal</h2>
                    <p class="mt-0.5 text-xs text-slate-400">
                        Menampilkan {{ count($import->errors) }} baris.
                        @if ($import->failed_count + $import->duplicate_count > count($import->errors))
                            Daftar dibatasi {{ \App\Models\StudentImport::MAX_ERRORS }} baris; jumlah di atas tetap akurat.
                        @endif
                    </p>
                </div>

                <div class="max-h-[32rem] overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-white/5 dark:bg-ink-900">
                            <tr>
                                <th class="px-4 py-2 font-semibold">Baris</th>
                                <th class="px-4 py-2 font-semibold">NIM</th>
                                <th class="px-4 py-2 font-semibold">Alasan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                            @foreach ($import->errors as $error)
                                <tr>
                                    <td class="px-4 py-2 font-mono text-xs text-slate-400">{{ $error['line'] ?? '—' }}</td>
                                    <td class="px-4 py-2 font-mono text-xs text-slate-600 dark:text-slate-300">{{ $error['nim'] ?? '—' }}</td>
                                    <td class="px-4 py-2 text-slate-600 dark:text-slate-300">{{ $error['reason'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @elseif ($import->status === \App\Models\StudentImport::STATUS_COMPLETED)
            <div class="card">
                <x-empty-state icon="check-circle" title="Semua baris berhasil diimport"
                               description="Tidak ada baris yang ditolak."/>
            </div>
        @endif
    </div>

    @push('scripts')
        <script>
            function importStatus(endpoint, alreadyFinished) {
                return {
                    finished: alreadyFinished,
                    data: @js([
                        'progress' => $import->progress,
                        'total' => $import->total_rows,
                        'stalled' => $import->isStalled(),
                    ]),

                    start() {
                        if (this.finished) return;

                        const timer = setInterval(async () => {
                            try {
                                const response = await fetch(endpoint, { headers: { 'Accept': 'application/json' } });
                                if (!response.ok) return;

                                const payload = await response.json();
                                this.data = payload;

                                if (payload.finished) {
                                    clearInterval(timer);
                                    this.finished = true;
                                    // Reload once so the totals and the error
                                    // table render server-side rather than
                                    // being duplicated in JS.
                                    window.location.reload();
                                }
                            } catch (e) {
                                clearInterval(timer);
                            }
                        }, 2000);
                    },
                };
            }
        </script>
    @endpush
</x-layouts.app>
