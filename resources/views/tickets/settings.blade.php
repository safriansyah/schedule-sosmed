@php
    // Rows come from old input after a validation failure, so a rejected save
    // never throws away what the admin typed.
    $rows = old('formats', array_map(fn ($f) => [
        'code' => $f['code'],
        'label' => $f['label'],
        'pattern' => $f['pattern'],
        'reset' => $f['reset'],
    ], $formats));

    $defaultIndex = (int) old('default', collect($formats)->search(fn ($f) => $f['default']) ?: 0);
@endphp

<x-layouts.app title="Format ID Tiket">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Format ID Tiket</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Boleh lebih dari satu — TKU untuk keuangan, TKB untuk yang lain. Nomor lama tidak pernah diubah.
            </p>
        </div>

        <a href="{{ route('tickets.index') }}" class="btn-outline">
            <x-icon name="file-text" class="h-4 w-4"/> Semua Tiket
        </a>
    </x-slot:header>

    <div class="grid gap-6 lg:grid-cols-3">
        <form method="POST" action="{{ route('tickets.settings.update') }}" class="lg:col-span-2"
              x-data="formatList(@js(array_values($rows)), {{ $defaultIndex }}, {{ $maxFormats }})">
            @csrf
            @method('PUT')

            @error('formats')
                <p class="form-error mb-3 rounded-xl bg-rose-500/10 p-3">{{ $message }}</p>
            @enderror

            <div class="space-y-3">
                <template x-for="(row, i) in rows" :key="row.uid">
                    <div class="card p-4" :class="i === defaultIndex && 'border-brand-500/40 bg-brand-500/[0.03]'">
                        <div class="grid gap-3 sm:grid-cols-12">
                            <div class="sm:col-span-3">
                                <label class="label" :for="'code-' + i">Kode</label>
                                <input :id="'code-' + i" :name="'formats[' + i + '][code]'" x-model="row.code"
                                       @input="row.code = row.code.toUpperCase()"
                                       maxlength="8" required placeholder="TKU"
                                       class="input font-mono font-bold uppercase">
                            </div>

                            <div class="sm:col-span-9">
                                <label class="label" :for="'label-' + i">Catatan — untuk apa format ini</label>
                                <input :id="'label-' + i" :name="'formats[' + i + '][label]'" x-model="row.label"
                                       maxlength="64" placeholder="Tiket Keuangan" class="input">
                            </div>

                            <div class="sm:col-span-7">
                                <label class="label" :for="'pattern-' + i">Pola</label>
                                <input :id="'pattern-' + i" :name="'formats[' + i + '][pattern]'" x-model="row.pattern"
                                       maxlength="64" required placeholder="TKU-{YYYY}{MM}-{SEQ:4}"
                                       class="input font-mono">
                            </div>

                            <div class="sm:col-span-5">
                                <label class="label" :for="'reset-' + i">Nomor urut kembali ke 1</label>
                                <select :id="'reset-' + i" :name="'formats[' + i + '][reset]'" x-model="row.reset"
                                        class="input" :disabled="implied(row.pattern)">
                                    @foreach ($resetOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>

                                {{-- A disabled select is not submitted; without
                                     this the saved value would silently reset. --}}
                                <template x-if="implied(row.pattern)">
                                    <input type="hidden" :name="'formats[' + i + '][reset]'" :value="row.reset">
                                </template>
                            </div>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3 dark:border-white/5">
                            <div class="min-w-0">
                                <p class="text-[11px] uppercase tracking-wide text-slate-400">Pratinjau</p>
                                <p class="font-mono text-base font-bold text-slate-800 dark:text-white"
                                   x-text="preview(row.pattern)"></p>

                                <p x-show="implied(row.pattern)" x-cloak class="mt-1 text-[11px] text-amber-600 dark:text-amber-400">
                                    <x-icon name="alert" class="mr-0.5 inline h-3 w-3"/>
                                    Pola memuat token tanggal, jadi nomor otomatis mulai dari 1 tiap periode itu.
                                </p>

                                <template x-if="usage[row.code]">
                                    <p class="mt-1 text-[11px] text-slate-400">
                                        <span x-text="usage[row.code].issued"></span> tiket sudah memakai kode ini ·
                                        berikutnya <span class="font-mono" x-text="usage[row.code].next"></span>
                                    </p>
                                </template>
                            </div>

                            <div class="flex shrink-0 items-center gap-3">
                                <label class="flex cursor-pointer items-center gap-2 text-xs font-medium text-slate-600 dark:text-slate-300">
                                    <input type="radio" name="default" :value="i" x-model.number="defaultIndex"
                                           class="h-4 w-4 border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-white/20 dark:bg-ink-850">
                                    Default
                                </label>

                                <button type="button" @click="remove(i)" x-show="rows.length > 1"
                                        class="text-slate-400 transition hover:text-rose-500" title="Hapus format">
                                    <x-icon name="trash" class="h-4 w-4"/>
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-2">
                <button type="button" @click="add()" x-show="rows.length < max" class="btn-outline">
                    <x-icon name="plus" class="h-4 w-4"/> Tambah Format
                </button>

                <button class="btn-primary"><x-icon name="check" class="h-4 w-4"/> Simpan Semua</button>

                <span class="text-xs text-slate-400">
                    <span x-text="rows.length"></span> dari {{ $maxFormats }} format
                </span>
            </div>

            <p class="mt-3 text-xs text-slate-400">
                Yang ditandai <span class="font-semibold">Default</span> dipakai untuk tiket yang dibuat otomatis —
                dari komentar Instagram dan dari Generate Ticket. Tiket manual bisa memilih formatnya sendiri.
            </p>

            {{-- Rendering the tokens client-side keeps the preview live without
                 a round trip. The server validates the same rules on save, so
                 this is convenience, never the guarantee. --}}
            <script>
                function formatList(initial, defaultIndex, max) {
                    return {
                        rows: initial.map((r, i) => ({ ...r, uid: 'r' + i + Date.now() })),
                        defaultIndex,
                        max,
                        usage: @js($usage),

                        add() {
                            if (this.rows.length >= this.max) return
                            this.rows.push({
                                uid: 'r' + Date.now() + Math.random(),
                                code: '', label: '', pattern: 'TKX-{YYYY}{MM}-{SEQ:4}', reset: 'never',
                            })
                        },

                        remove(i) {
                            if (this.rows.length <= 1) return
                            this.rows.splice(i, 1)
                            // The default index points at a position, so it has
                            // to move when a row above it disappears.
                            if (this.defaultIndex >= this.rows.length) this.defaultIndex = this.rows.length - 1
                        },

                        implied(pattern) {
                            return /\{(YYYY|YY|MM|DD)\}/.test(pattern || '')
                        },

                        preview(pattern) {
                            const now = new Date()
                            const pad = (n, w) => String(n).padStart(w, '0')

                            return String(pattern || '')
                                .replaceAll('{YYYY}', now.getFullYear())
                                .replaceAll('{YY}', pad(now.getFullYear() % 100, 2))
                                .replaceAll('{MM}', pad(now.getMonth() + 1, 2))
                                .replaceAll('{DD}', pad(now.getDate(), 2))
                                .replace(/\{SEQ:(\d+)\}/g, (_, d) => pad(1, Number(d)))
                                .replaceAll('{SEQ}', '1')
                        },
                    }
                }
            </script>
        </form>

        <div class="space-y-6">
            <div class="card p-5">
                <h2 class="mb-3 text-base font-bold text-slate-800 dark:text-white">Token yang tersedia</h2>

                <dl class="space-y-2.5 text-sm">
                    @foreach ($tokens as $token => $meaning)
                        <div>
                            <dt class="font-mono text-xs font-bold text-brand-600 dark:text-brand-400">{{ $token }}</dt>
                            <dd class="text-xs text-slate-500 dark:text-slate-400">{{ $meaning }}</dd>
                        </div>
                    @endforeach
                </dl>

                <p class="mt-3 border-t border-slate-100 pt-3 text-[11px] text-slate-400 dark:border-white/5">
                    Maksimum {{ $maxLength }} karakter setelah pola diisi. Kode 2–8 huruf/angka, tanpa spasi,
                    dan tidak boleh sama antar format.
                </p>
            </div>

            <div class="card p-5">
                <h2 class="mb-1 text-base font-bold text-slate-800 dark:text-white">Contoh pola</h2>
                <p class="mb-3 text-xs text-slate-400">Salin ke kolom pola yang sedang diisi.</p>

                <div class="space-y-2">
                    @foreach ($examples as $example)
                        <div class="rounded-xl border border-slate-200 p-3 dark:border-white/10">
                            <p class="font-mono text-sm font-bold text-slate-800 dark:text-white">{{ $example['sample'] }}</p>
                            <p class="font-mono text-[11px] text-slate-400">{{ $example['pattern'] }}</p>
                            <p class="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">{{ $example['label'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card border-amber-500/30 bg-amber-500/[0.05] p-4">
                <p class="flex items-start gap-2 text-xs text-slate-600 dark:text-slate-300">
                    <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0 text-amber-500"/>
                    <span>
                        <span class="font-semibold">{{ number_format($ticketCount) }} tiket</span> sudah terbit
                        @if ($lastNumber)
                            (terakhir <span class="font-mono">{{ $lastNumber }}</span>)
                        @endif
                        dan nomornya tidak akan berubah. Format baru hanya berlaku untuk tiket berikutnya,
                        jadi arsip lama tetap bisa dicari dengan nomor yang sudah dipakai.
                        Menghapus sebuah format juga tidak menghapus tiket yang memakainya.
                    </span>
                </p>
            </div>
        </div>
    </div>
</x-layouts.app>
