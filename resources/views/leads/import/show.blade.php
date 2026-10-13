<x-layouts.app title="Hasil Import Lead">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Hasil Import Lead</h1>
            <p class="mt-0.5 truncate text-sm text-slate-400">
                {{ $import->original_name }} · sumber <span class="{{ $import->source->badge() }}">{{ $import->source->label() }}</span>
                · {{ $import->created_at->translatedFormat('d M Y H:i') }} · {{ $import->creator?->name ?? 'Sistem' }}
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('leads.import.index') }}" class="btn-outline">
                <x-icon name="upload" class="h-4 w-4"/> Import Lagi
            </a>
            @can(\App\Enums\Permission::ViewLeads->value)
                <a href="{{ route('leads.index', ['sumber' => $import->source->value]) }}" class="btn-primary">
                    <x-icon name="target" class="h-4 w-4"/> Lihat Daftar Lead
                </a>
            @endcan
        </div>
    </x-slot:header>

    @if ($import->status === \App\Models\LeadImport::STATUS_FAILED)
        <div class="card mb-6 border-rose-500/30 bg-rose-500/[0.06] p-4">
            <div class="flex items-start gap-3">
                <x-icon name="alert" class="mt-0.5 h-5 w-5 shrink-0 text-rose-500"/>
                <div>
                    <p class="font-semibold text-rose-600 dark:text-rose-400">Import gagal</p>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ $import->error_message }}</p>
                    <p class="mt-1 text-xs text-slate-400">Perbaiki berkasnya lalu unggah ulang. Tidak ada data yang setengah tersimpan dari baris yang gagal.</p>
                </div>
            </div>
        </div>
    @endif

    <div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-5">
        <x-stat-card label="Baris dibaca" :value="number_format($import->total_rows)" icon="file-text" tone="slate"/>
        <x-stat-card label="Lead baru" :value="number_format($import->imported_count)" icon="user-plus" tone="emerald"/>
        <x-stat-card label="Sudah ada (dilengkapi)" :value="number_format($import->updated_count)" icon="refresh" tone="cyan"/>
        <x-stat-card label="Duplikat di berkas" :value="number_format($import->duplicate_count)" icon="hash" tone="amber"/>
        <x-stat-card label="Gagal" :value="number_format($import->failed_count)" icon="x" tone="rose"/>
    </div>

    @if (! empty($import->errors))
        <div class="card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-5 dark:border-white/5">
                <div>
                    <h2 class="text-base font-bold text-slate-800 dark:text-white">Baris yang Dilewati</h2>
                    <p class="mt-0.5 text-xs text-slate-400">Perbaiki di Excel lalu import ulang; baris yang sudah masuk tidak akan dobel.</p>
                </div>
                <a href="{{ route('leads.import.errors', $import) }}" class="btn-outline">
                    <x-icon name="download" class="h-4 w-4"/> Download Baris Gagal
                </a>
            </div>

            <div class="max-h-[28rem] overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-white/5 dark:bg-ink-850">
                        <tr>
                            <th class="px-4 py-2 font-semibold">Baris</th>
                            <th class="px-4 py-2 font-semibold">No HP</th>
                            <th class="px-4 py-2 font-semibold">Alasan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                        @foreach ($import->errors as $error)
                            <tr>
                                <td class="px-4 py-2 font-mono text-xs text-slate-500">{{ $error['line'] ?? '' }}</td>
                                <td class="px-4 py-2 font-mono text-xs text-slate-600 dark:text-slate-300">{{ $error['no_hp'] ?? '—' }}</td>
                                <td class="px-4 py-2 text-slate-600 dark:text-slate-300">{{ $error['reason'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-layouts.app>
