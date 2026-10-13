<x-layouts.app title="Import Data Lead">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Import Data Lead</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Pilih sumber data, unggah Excel atau CSV, periksa kolomnya, lalu import.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            @can(\App\Enums\Permission::ViewLeads->value)
                <a href="{{ route('leads.index') }}" class="btn-outline">
                    <x-icon name="target" class="h-4 w-4"/> Daftar Lead
                </a>
            @endcan
            <a href="{{ route('leads.import.template') }}" class="btn-outline">
                <x-icon name="download" class="h-4 w-4"/> Unduh Template
            </a>
        </div>
    </x-slot:header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <form method="POST" action="{{ route('leads.import.store') }}" enctype="multipart/form-data" class="card p-5">
                @csrf

                <h2 class="mb-4 text-base font-bold text-slate-800 dark:text-white">Unggah Berkas</h2>

                <div class="space-y-4">
                    <div>
                        <label for="source" class="label">1. Sumber data <span class="text-rose-500">*</span></label>
                        <select id="source" name="source" class="input" required>
                            <option value="">Pilih sumber data…</option>
                            @foreach ($sources as $value => $label)
                                <option value="{{ $value }}" @selected(old('source') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-slate-400">Berlaku untuk semua baris di berkas ini.</p>
                        @error('source') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="file" class="label">2. Berkas .xlsx, .xls, atau .csv (maks 20 MB) <span class="text-rose-500">*</span></label>
                        <input id="file" name="file" type="file" accept=".xlsx,.xls,.csv,.txt" required
                               class="input file:mr-3 file:rounded-lg file:border-0 file:bg-brand-600 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white">
                        @error('file') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-4 rounded-xl bg-slate-50 p-4 text-xs text-slate-500 dark:bg-white/[0.03] dark:text-slate-400">
                    <p class="font-semibold text-slate-600 dark:text-slate-300">Yang terjadi setelah unggah:</p>
                    <ol class="mt-2 list-decimal space-y-1 pl-4">
                        <li>Berkas dibaca, belum ada data yang disimpan.</li>
                        <li>Anda memeriksa hasil pemetaan kolom dan contoh barisnya.</li>
                        <li>Setelah dikonfirmasi, data lead diimport. No HP yang sudah ada tidak dobel — datanya dilengkapi.</li>
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
                            <a href="{{ route('leads.import.show', $import) }}"
                               class="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 p-3 transition hover:bg-slate-50 dark:border-white/10 dark:hover:bg-white/[0.02]">
                                <x-status-badge :status="$import->status"/>

                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $import->original_name }}</p>
                                    <p class="text-[11px] text-slate-400">
                                        <span class="{{ $import->source->badge() }}">{{ $import->source->label() }}</span>
                                        · {{ $import->created_at->translatedFormat('d M Y H:i') }}
                                        · {{ $import->creator?->name ?? 'Sistem' }}
                                    </p>
                                </div>

                                @if ($import->status === \App\Models\LeadImport::STATUS_COMPLETED)
                                    <span class="text-xs text-slate-400">
                                        <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ number_format($import->imported_count) }}</span> baru
                                        @if ($import->updated_count)
                                            · {{ number_format($import->updated_count) }} sudah ada
                                        @endif
                                        @if ($import->failed_count + $import->duplicate_count)
                                            · <span class="font-semibold text-rose-500">{{ number_format($import->failed_count + $import->duplicate_count) }}</span> dilewati
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

        <div class="card p-5">
            <h2 class="mb-1 text-base font-bold text-slate-800 dark:text-white">Kolom yang Dikenali</h2>
            <p class="mb-4 text-xs text-slate-400">
                Judul kolom dicocokkan tanpa memedulikan huruf besar/kecil dan tanda baca.
                Kolom lain tetap disimpan sebagai data tambahan.
            </p>

            <dl class="space-y-3 text-xs">
                @foreach ($mapping as $field => $aliases)
                    <div>
                        <dt class="font-semibold text-slate-700 dark:text-slate-200">
                            {{ $labels[$field] }}
                            @if (in_array($field, $required, true))
                                <span class="text-rose-500">*</span>
                            @else
                                <span class="font-normal text-slate-400">(opsional)</span>
                            @endif
                        </dt>
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
        </div>
    </div>
</x-layouts.app>
