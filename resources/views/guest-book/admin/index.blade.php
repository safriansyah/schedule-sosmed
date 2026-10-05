<x-layouts.app title="Buku Tamu / Antrian">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Buku Tamu / Antrian</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Antrian layanan tatap muka. Panggil, layani, atau jadikan tiket — monitor ikut berubah sendiri.
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

    <div x-data="guestQueue(@js(route('guest-book.admin.rows')), @js(['date' => $date->toDateString(), 'filter' => $filter]))"
         x-init="start()">

        <div class="card mb-4 flex flex-wrap items-end gap-3 p-4">
            <div>
                <label for="date" class="label">Tanggal</label>
                <input id="date" type="date" class="input" x-model="query.date" @change="refresh()"
                       max="{{ now('Asia/Jakarta')->toDateString() }}">
            </div>

            <div class="flex flex-wrap gap-1 rounded-xl bg-slate-100 p-1 dark:bg-white/5">
                @foreach (['active' => 'Aktif', 'finished' => 'Selesai & tiket', 'all' => 'Semua'] as $key => $label)
                    <button type="button" @click="query.filter = '{{ $key }}'; refresh()"
                            class="rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                            :class="query.filter === '{{ $key }}' ? 'bg-white text-brand-700 shadow-sm dark:bg-ink-850 dark:text-brand-300' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400'">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <p class="ml-auto flex items-center gap-1.5 text-xs text-slate-400">
                <x-icon name="refresh" class="h-3.5 w-3.5" ::class="loading && 'animate-spin'"/>
                <span x-text="paused ? 'Pembaruan otomatis dijeda saat Anda memilih' : 'Diperbarui otomatis tiap 5 detik'"></span>
            </p>
        </div>

        <div x-ref="rows" @focusin="paused = true" @focusout="paused = false">
            @include('guest-book.admin.rows')
        </div>
    </div>

    @push('scripts')
        <script>
            function guestQueue(endpoint, initialQuery) {
                return {
                    query: initialQuery,
                    loading: false,
                    paused: false,
                    busy: false,

                    start() {
                        setInterval(() => { if (!this.paused && !this.busy && !document.hidden) this.refresh(); }, 5000);
                    },

                    async refresh() {
                        this.loading = true;
                        try {
                            const url = endpoint + '?' + new URLSearchParams(this.query);
                            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                            if (response.ok) this.$refs.rows.innerHTML = await response.text();
                            history.replaceState(null, '', '?' + new URLSearchParams(this.query));
                        } finally {
                            this.loading = false;
                        }
                    },

                    async act(url, payload, confirmText = null) {
                        if (this.busy || (confirmText && !confirm(confirmText))) return;

                        this.busy = true;
                        try {
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

                            if (!response.ok) {
                                const first = body.errors ? Object.values(body.errors)[0][0] : null;
                                throw new Error(first || body.message || 'Gagal memproses antrian.');
                            }

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
