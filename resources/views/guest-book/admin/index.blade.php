<x-layouts.app title="Buku Tamu / Antrian">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Buku Tamu / Antrian</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Antrian layanan tatap muka. Panggil, layani, selesaikan, atau jadikan tiket — monitor ikut berubah sendiri.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('guest-book.monitor') }}" target="_blank" rel="noopener" class="btn-outline">
                <x-icon name="monitor" class="h-4 w-4"/> Buka Monitor
            </a>
            <a href="{{ route('guest-book.create') }}" target="_blank" rel="noopener" class="btn-primary">
                <x-icon name="plus" class="h-4 w-4"/> Form Buku Tamu
            </a>
        </div>
    </x-slot:header>

    @php
        $me = auth()->user();
        $meIsOperator = $operators->contains('id', $me->id);
    @endphp

    <div x-data="guestQueue({
            rowsUrl: @js(route('guest-book.admin.rows')),
            exportUrl: @js(route('guest-book.admin.export')),
            query: @js(['q' => request('q', ''), 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'type' => request('type', ''), 'filter' => $filter]),
            defaultOperator: @js($meIsOperator ? (string) $me->id : ''),
            defaultProcess: @js(array_key_first($processes)),
            defaultResolution: @js(array_key_first($resolutions)),
         })"
         x-init="start()"
         @keydown.escape.window="closeModal()">

        {{-- Filters --}}
        <div class="card mb-4 p-4">
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-12 lg:items-end">
                <div class="col-span-2 lg:col-span-4">
                    <label for="gb-q" class="label">Cari</label>
                    <div class="relative">
                        <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"/>
                        <input id="gb-q" type="search" class="input pl-9" placeholder="Nama, NIM, No. WA/HP, nomor antrian…"
                               x-model="query.q" @input.debounce.400ms="refresh()">
                    </div>
                </div>
                <div class="lg:col-span-2">
                    <label for="gb-from" class="label">Dari tanggal</label>
                    <input id="gb-from" type="date" class="input" x-model="query.from" @change="refresh()">
                </div>
                <div class="lg:col-span-2">
                    <label for="gb-to" class="label">Sampai tanggal</label>
                    <input id="gb-to" type="date" class="input" x-model="query.to" @change="refresh()">
                </div>
                <div class="col-span-2 sm:col-span-1 lg:col-span-2">
                    <label for="gb-type" class="label">Jenis</label>
                    <select id="gb-type" class="input" x-model="query.type" @change="refresh()">
                        <option value="">Semua jenis</option>
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-span-2 sm:col-span-1 lg:col-span-2">
                    <a :href="exportHref()" class="btn-outline w-full">
                        <x-icon name="download" class="h-4 w-4"/> Download XLSX
                    </a>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3 dark:border-white/5">
                <div class="flex flex-wrap gap-1 rounded-xl bg-slate-100 p-1 dark:bg-white/5">
                    @foreach (['active' => 'Aktif', 'finished' => 'Selesai & tiket', 'all' => 'Semua'] as $key => $label)
                        <button type="button" @click="query.filter = '{{ $key }}'; refresh()"
                                class="min-h-9 rounded-lg px-3 py-2 text-xs font-semibold transition"
                                :class="query.filter === '{{ $key }}' ? 'bg-white text-brand-700 shadow-sm dark:bg-ink-850 dark:text-brand-300' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400'">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-300"
                            x-show="! isToday()" x-cloak @click="resetToday()">
                        Kembali ke hari ini
                    </button>
                    <p class="flex items-center gap-1.5 text-xs text-slate-400">
                        <x-icon name="refresh" class="h-3.5 w-3.5" ::class="loading && 'animate-spin'"/>
                        <span x-text="modal || paused ? 'Pembaruan otomatis dijeda' : 'Diperbarui otomatis tiap 5 detik'"></span>
                    </p>
                </div>
            </div>
        </div>

        <div x-ref="rows" @focusin="paused = true" @focusout="paused = false" :class="loading && 'opacity-60 transition'">
            @include('guest-book.admin.rows')
        </div>

        {{-- ============================ Modals ============================ --}}
        <div x-show="modal" x-cloak class="fixed inset-0 z-[90] flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" @click="closeModal()"></div>

            <div class="card relative max-h-[calc(100dvh-2rem)] w-full max-w-lg overflow-y-auto p-6 shadow-2xl"
                 x-show="modal" x-transition>
                <div class="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <p class="text-base font-bold text-slate-800 dark:text-white"
                           x-text="{ complete: 'Selesaikan Antrian', detail: 'Detail Antrian', ticket: 'Jadikan Tiket' }[modal]"></p>
                        <p class="text-xs text-slate-400" x-show="entry">
                            No. <span class="font-mono font-bold" x-text="entry?.number"></span> ·
                            <span x-text="entry?.name"></span>
                        </p>
                    </div>
                    <button type="button" @click="closeModal()" aria-label="Tutup"
                            class="-m-2 grid h-9 w-9 shrink-0 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-white/5 dark:hover:text-slate-200">
                        <x-icon name="x" class="h-5 w-5"/>
                    </button>
                </div>

                {{-- Selesai --}}
                <form x-show="modal === 'complete'" @submit.prevent="submitComplete()" class="space-y-4" data-no-submit-guard>
                    <p class="rounded-xl bg-slate-50 p-3 text-xs text-slate-500 dark:bg-white/5 dark:text-slate-400">
                        <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="entry?.type + ' · ' + entry?.service"></span>
                    </p>

                    <div>
                        <label for="gb-process" class="label">Proses layanan <span class="text-rose-500">*</span></label>
                        <select id="gb-process" class="input" x-model="form.service_process" required>
                            @foreach ($processes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="form-error" x-show="errors.service_process" x-text="errors.service_process"></p>
                    </div>

                    <div>
                        <label for="gb-resolution" class="label">Penyelesaian <span class="text-rose-500">*</span></label>
                        <select id="gb-resolution" class="input" x-model="form.resolution" required>
                            @foreach ($resolutions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="form-error" x-show="errors.resolution" x-text="errors.resolution"></p>
                    </div>

                    <div>
                        <label for="gb-operator" class="label">Operator yang menyelesaikan <span class="text-rose-500">*</span></label>
                        <select id="gb-operator" class="input" x-model="form.completed_by" required>
                            <option value="">Pilih operator…</option>
                            <x-operator-options :operators="$operators"/>
                        </select>
                        <p class="form-error" x-show="errors.completed_by" x-text="errors.completed_by"></p>
                    </div>

                    <div>
                        <label for="gb-note" class="label">Catatan <span class="text-xs font-normal text-slate-400">(opsional)</span></label>
                        <textarea id="gb-note" rows="2" maxlength="1000" class="input" x-model="form.completion_note"
                                  placeholder="Mis. berkas sudah diserahkan"></textarea>
                    </div>

                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button type="button" class="btn-outline" @click="closeModal()">Batal</button>
                        <button class="btn-success" :disabled="busy">
                            <x-icon name="refresh" class="h-4 w-4 animate-spin" x-show="busy" x-cloak/>
                            <x-icon name="check" class="h-4 w-4" x-show="! busy"/>
                            <span x-text="busy ? 'Menyimpan…' : 'Selesai'">Selesai</span>
                        </button>
                    </div>
                </form>

                {{-- Detail --}}
                <div x-show="modal === 'detail'" class="space-y-4">
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                        <template x-for="[label, key] in detailFields" :key="key">
                            <div :class="['description', 'service', 'completion_note'].includes(key) ? 'col-span-2' : ''" x-show="entry && entry[key]">
                                <dt class="text-xs text-slate-400" x-text="label"></dt>
                                <dd class="whitespace-pre-line break-words font-medium text-slate-700 dark:text-slate-200" x-text="entry?.[key]"></dd>
                            </div>
                        </template>
                    </dl>

                    <template x-if="entry?.ticket">
                        <a :href="entry.ticket_url" class="flex items-center gap-2 rounded-xl border border-violet-500/30 bg-violet-500/[0.06] p-3 text-sm font-semibold text-violet-700 dark:text-violet-300">
                            <x-icon name="file-text" class="h-4 w-4"/> Tiket <span class="font-mono" x-text="entry.ticket"></span>
                            <span class="ml-auto text-xs">Buka →</span>
                        </a>
                    </template>

                    <template x-if="entry?.signature_url">
                        <div>
                            <p class="mb-1 text-xs text-slate-400">Paraf</p>
                            <img :src="entry.signature_url" alt="Paraf" class="h-20 w-48 rounded-xl border border-slate-200 bg-white object-contain p-1 dark:border-white/10 dark:invert">
                        </div>
                    </template>

                    <div class="flex justify-end">
                        <button type="button" class="btn-outline" @click="closeModal()">Tutup</button>
                    </div>
                </div>

                {{-- Add Ticket --}}
                <form x-show="modal === 'ticket'" @submit.prevent="submitTicket()" class="space-y-4" data-no-submit-guard>
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        Data tamu disalin ke tiket baru dengan sumber <strong>Buku Tamu / Antrian</strong>, dan antrian ini keluar dari monitor.
                    </p>

                    @if ($canAssign)
                        <div>
                            <label for="gb-assignee" class="label">Tugaskan ke</label>
                            <select id="gb-assignee" class="input" x-model="form.assigned_to">
                                <option value="">— Tugaskan nanti —</option>
                                <x-operator-options :operators="$operators"/>
                            </select>
                            <p class="form-error" x-show="errors.assigned_to" x-text="errors.assigned_to"></p>
                        </div>
                    @endif

                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button type="button" class="btn-outline" @click="closeModal()">Batal</button>
                        <button class="btn-primary" :disabled="busy">
                            <x-icon name="refresh" class="h-4 w-4 animate-spin" x-show="busy" x-cloak/>
                            <x-icon name="plus" class="h-4 w-4" x-show="! busy"/>
                            <span x-text="busy ? 'Membuat tiket…' : 'Buat Tiket'">Buat Tiket</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            function guestQueue(config) {

                return {
                    query: config.query,
                    loading: false,
                    paused: false,
                    busy: false,
                    modal: null,
                    entry: null,
                    form: {},
                    errors: {},
                    detailFields: [
                        ['Tanggal', 'date'], ['Status', 'status'],
                        ['Nama', 'name'], ['NIM', 'nim'],
                        ['Jenis kelamin', 'gender'], ['Jenis kunjungan', 'type'],
                        ['No. WhatsApp', 'whatsapp'], ['No. HP', 'phone'],
                        ['Layanan / keluhan', 'service'],
                        ['Deskripsi', 'description'],
                        ['Jam daftar', 'registered_at'], ['Jam dipanggil', 'called_at'],
                        ['Selesai', 'finished_at'], ['Operator yang menyelesaikan', 'completed_by'],
                        ['Proses layanan', 'process'], ['Penyelesaian', 'resolution'],
                        ['Catatan', 'completion_note'], ['Diproses oleh', 'handler'],
                    ],

                    start() {
                        setInterval(() => {
                            if (!this.paused && !this.busy && !this.modal && !document.hidden) this.refresh();
                        }, 5000);
                    },

                    todayString() {
                        return new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Jakarta' });
                    },

                    isToday() {
                        const t = this.todayString();
                        return this.query.from === t && this.query.to === t;
                    },

                    resetToday() {
                        this.query.from = this.query.to = this.todayString();
                        this.refresh();
                    },

                    params() {
                        return new URLSearchParams(Object.fromEntries(Object.entries(this.query).filter(([, v]) => v !== '' && v !== null)));
                    },

                    exportHref() {
                        return config.exportUrl + '?' + this.params() + '&format=xlsx';
                    },

                    async refresh() {
                        this.loading = true;
                        try {
                            const response = await fetch(config.rowsUrl + '?' + this.params(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                            if (!response.ok) throw new Error();
                            this.$refs.rows.innerHTML = await response.text();
                            history.replaceState(null, '', '?' + this.params());
                        } catch (e) {
                            window.toast('Gagal memuat antrian. Periksa koneksi lalu coba lagi.', 'error');
                        } finally {
                            this.loading = false;
                        }
                    },

                    open(type, entry, form = {}) {
                        this.entry = entry;
                        this.errors = {};
                        this.form = form;
                        this.modal = type;
                    },

                    openComplete(entry) {
                        this.open('complete', entry, {
                            service_process: config.defaultProcess,
                            resolution: config.defaultResolution,
                            completed_by: config.defaultOperator,
                            completion_note: '',
                        });
                    },

                    openDetail(entry) { this.open('detail', entry); },

                    openTicket(entry) { this.open('ticket', entry, { assigned_to: '' }); },

                    closeModal() {
                        if (this.busy) return;
                        this.modal = null;
                    },

                    async post(url, payload) {
                        const response = await fetch(url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            },
                            body: JSON.stringify(payload),
                        });
                        const body = await response.json().catch(() => ({}));

                        if (response.status === 422 && body.errors) {
                            this.errors = Object.fromEntries(Object.entries(body.errors).map(([k, v]) => [k, v[0]]));
                            throw new Error(Object.values(this.errors)[0]);
                        }
                        if (!response.ok) throw new Error(body.message || 'Gagal memproses antrian.');

                        return body;
                    },

                    async submitComplete() {
                        if (this.busy) return;
                        this.busy = true;
                        try {
                            const body = await this.post(this.entry.complete_url, this.form);
                            window.toast(body.message, 'success');
                            this.busy = false;
                            // Straight into the detail of what was just recorded.
                            this.open('detail', body.entry);
                            await this.refresh();
                        } catch (error) {
                            window.toast(error.message, 'error');
                        } finally {
                            this.busy = false;
                        }
                    },

                    async submitTicket() {
                        if (this.busy) return;
                        this.busy = true;
                        try {
                            const body = await this.post(this.entry.ticket_create_url, this.form);
                            window.toast(body.message, 'success');
                            this.busy = false;
                            this.closeModal();
                            await this.refresh();
                        } catch (error) {
                            window.toast(error.message, 'error');
                        } finally {
                            this.busy = false;
                        }
                    },

                    async act(url, payload, confirmText = null) {
                        if (this.busy || (confirmText && !confirm(confirmText))) return;

                        this.busy = true;
                        try {
                            const body = await this.post(url, payload);
                            window.toast(body.message, 'success');
                            this.paused = false;
                            await this.refresh();
                        } catch (error) {
                            window.toast(error.message, 'error');
                        } finally {
                            this.busy = false;
                        }
                    },
                };
            }
        </script>
    @endpush
</x-layouts.app>
