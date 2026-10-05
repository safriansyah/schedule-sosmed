{{--
    The waiting-room screen. Built to be read from across a room on a TV:
    numbers as large as the screen allows, nothing to click except the sound
    and theme toggles.

    Light and dark: follows the app-wide theme (the same $store.theme the
    rest of the app uses), and the toggle in the header switches it. The
    choice is remembered by that browser, so a TV keeps its own setting.

    Live by polling the whitelisted feed every few seconds, so an operator's
    "Panggil" or "Add Ticket" shows up here without anyone touching the TV.
--}}
<x-layouts.public title="Monitor Antrian" wide class="!bg-slate-100 text-slate-800 dark:!bg-ink-950 dark:text-white">
    <div x-data="queueMonitor(@js(route('guest-book.monitor.feed')), @js($initial))" x-init="start()"
         class="flex min-h-[calc(100vh-3rem)] flex-col gap-6">

        <header class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="grid h-12 w-12 place-items-center rounded-2xl bg-gradient-to-br from-brand-500 to-accent-500 text-white shadow-lg shadow-brand-600/30">
                    <x-icon name="monitor" class="h-6 w-6"/>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.25em] text-brand-600 dark:text-brand-300">{{ $branding->name() }}</p>
                    <h1 class="text-2xl font-extrabold tracking-tight lg:text-3xl">Monitor Antrian Layanan</h1>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="button" @click="toggleSound()"
                        class="min-h-10 rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50
                               dark:border-white/10 dark:bg-transparent dark:text-slate-300 dark:hover:bg-white/5"
                        x-text="sound ? '🔔 Suara aktif' : '🔕 Aktifkan suara'"></button>

                {{-- Light / dark --}}
                <button type="button" @click="$store.theme.toggle()"
                        class="grid h-10 w-10 place-items-center rounded-xl border border-slate-300 bg-white text-slate-600 transition hover:bg-slate-50
                               dark:border-white/10 dark:bg-transparent dark:text-slate-300 dark:hover:bg-white/5"
                        :aria-label="$store.theme.dark ? 'Ganti ke mode terang' : 'Ganti ke mode gelap'"
                        :title="$store.theme.dark ? 'Mode terang' : 'Mode gelap'">
                    <x-icon name="sun" class="h-5 w-5" x-show="$store.theme.dark"/>
                    <x-icon name="moon" class="h-5 w-5" x-show="! $store.theme.dark" x-cloak/>
                </button>

                <div class="text-right">
                    <p class="font-mono text-3xl font-bold tabular-nums lg:text-4xl" x-text="clock"></p>
                    <p class="text-xs text-slate-500 dark:text-slate-400" x-text="today"></p>
                </div>
            </div>
        </header>

        <div class="grid flex-1 gap-6 lg:grid-cols-5">
            {{-- Being called / served: the reason the screen exists. --}}
            <section class="flex flex-col rounded-3xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-3 lg:p-7
                            dark:border-white/10 dark:bg-white/[0.03] dark:shadow-none">
                <h2 class="mb-4 flex items-center gap-2 text-sm font-bold uppercase tracking-[0.2em] text-cyan-700 dark:text-cyan-300">
                    <span class="relative flex h-2.5 w-2.5">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-cyan-400 opacity-75"></span>
                        <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-cyan-500 dark:bg-cyan-400"></span>
                    </span>
                    Sedang Dipanggil / Dilayani
                </h2>

                <template x-if="data.now.length === 0">
                    <div class="grid flex-1 place-items-center text-center text-slate-400 dark:text-slate-500">
                        <div>
                            <x-icon name="clock" class="mx-auto mb-3 h-12 w-12"/>
                            <p class="text-lg">Belum ada nomor yang dipanggil.</p>
                        </div>
                    </div>
                </template>

                <div class="grid gap-4" :class="data.now.length > 1 ? 'sm:grid-cols-2' : ''">
                    <template x-for="(item, i) in data.now" :key="item.number">
                        <div class="rounded-3xl border p-6 transition-all duration-500"
                             :class="[
                                item.status === 'called'
                                    ? 'border-amber-400 bg-gradient-to-br from-amber-100 to-amber-50 dark:border-amber-400/60 dark:from-amber-500/25 dark:to-amber-500/5'
                                    : 'border-cyan-400 bg-gradient-to-br from-cyan-100 to-cyan-50 dark:border-cyan-400/40 dark:from-cyan-500/20 dark:to-cyan-500/5',
                                highlight.includes(item.number) ? 'scale-[1.02] ring-4 ring-amber-400/70 dark:ring-amber-300/70' : '',
                             ]">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-xs font-bold uppercase tracking-[0.2em]"
                                   :class="item.status === 'called' ? 'text-amber-700 dark:text-amber-300' : 'text-cyan-700 dark:text-cyan-300'"
                                   x-text="item.status_label"></p>
                            </div>
                            <p class="mt-1 font-mono font-extrabold leading-none tracking-tight text-slate-900 dark:text-white"
                               :class="data.now.length > 1 ? 'text-7xl lg:text-8xl' : 'text-8xl lg:text-[11rem]'"
                               x-text="item.number"></p>
                            <p class="mt-3 truncate text-2xl font-bold lg:text-3xl" x-text="item.name"></p>
                            <p class="mt-1 text-sm font-semibold uppercase tracking-wide text-slate-600 lg:text-base dark:text-slate-300" x-text="item.service"></p>
                        </div>
                    </template>
                </div>
            </section>

            {{-- Waiting --}}
            <section class="flex flex-col rounded-3xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2 lg:p-7
                            dark:border-white/10 dark:bg-white/[0.03] dark:shadow-none">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-sm font-bold uppercase tracking-[0.2em] text-amber-700 dark:text-amber-300">Menunggu</h2>
                    <span class="rounded-full bg-amber-100 px-3 py-1 text-sm font-bold text-amber-700 dark:bg-amber-400/15 dark:text-amber-300"
                          x-text="data.waiting.length + ' orang'"></span>
                </div>

                <template x-if="data.waiting.length === 0">
                    <p class="py-10 text-center text-slate-400 dark:text-slate-500">Tidak ada antrian menunggu.</p>
                </template>

                <ol class="space-y-2.5 overflow-hidden">
                    <template x-for="item in data.waiting.slice(0, 12)" :key="item.number">
                        <li class="flex items-center gap-4 rounded-2xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200/70 dark:bg-white/[0.04] dark:ring-0">
                            <span class="w-20 shrink-0 font-mono text-3xl font-extrabold text-slate-900 lg:text-4xl dark:text-white" x-text="item.number"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-lg font-semibold" x-text="item.name"></span>
                                <span class="block truncate text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400" x-text="item.service"></span>
                            </span>
                        </li>
                    </template>
                </ol>

                <p x-show="data.waiting.length > 12" x-cloak class="mt-3 text-center text-sm text-slate-500 dark:text-slate-400"
                   x-text="'+ ' + (data.waiting.length - 12) + ' antrian lainnya'"></p>
            </section>
        </div>

        <footer class="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
            <span>
                Ambil nomor antrian di
                <span class="font-semibold text-slate-700 dark:text-slate-300">{{ preg_replace('#^https?://#', '', route('guest-book.create')) }}</span>
                · Selesai dilayani hari ini: <span class="font-semibold text-slate-700 dark:text-slate-300" x-text="data.served_today"></span>
            </span>
            <span class="flex items-center gap-1.5">
                <span class="h-2 w-2 rounded-full" :class="online ? 'bg-emerald-500 dark:bg-emerald-400' : 'bg-rose-500'"></span>
                <span x-text="online ? 'Terhubung · diperbarui ' + data.updated_at : 'Koneksi terputus — mencoba lagi…'"></span>
            </span>
        </footer>
    </div>

    @push('scripts')
        <script>
            function queueMonitor(endpoint, initial) {
                return {
                    data: initial,
                    online: true,
                    sound: false,
                    highlight: [],
                    clock: '',
                    today: '',
                    audio: null,

                    start() {
                        this.tick();
                        setInterval(() => this.tick(), 1000);
                        setInterval(() => this.poll(), 3000);
                    },

                    tick() {
                        const now = new Date();
                        const opts = { timeZone: 'Asia/Jakarta' };
                        this.clock = now.toLocaleTimeString('id-ID', { ...opts, hour: '2-digit', minute: '2-digit', second: '2-digit' });
                        this.today = now.toLocaleDateString('id-ID', { ...opts, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
                    },

                    async poll() {
                        try {
                            const response = await fetch(endpoint, { headers: { Accept: 'application/json' }, cache: 'no-store' });
                            if (!response.ok) throw new Error(response.status);
                            const next = await response.json();

                            // A number that is newly "called" gets a highlight
                            // and, if enabled, a chime — the cue to look up.
                            const before = new Set(this.data.now.filter(i => i.status === 'called').map(i => i.number));
                            const fresh = next.now.filter(i => i.status === 'called' && !before.has(i.number)).map(i => i.number);

                            this.data = next;
                            this.online = true;

                            if (fresh.length) {
                                this.highlight = fresh;
                                this.chime();
                                setTimeout(() => (this.highlight = []), 6000);
                            }
                        } catch (e) {
                            this.online = false;
                        }
                    },

                    // Browsers only allow sound after a click on the page,
                    // hence the toggle rather than sound by default.
                    toggleSound() {
                        this.sound = !this.sound;
                        if (this.sound) {
                            this.audio = this.audio || new (window.AudioContext || window.webkitAudioContext)();
                            this.chime();
                        }
                    },

                    chime() {
                        if (!this.sound || !this.audio) return;
                        [880, 660].forEach((freq, i) => {
                            const osc = this.audio.createOscillator();
                            const gain = this.audio.createGain();
                            osc.frequency.value = freq;
                            osc.connect(gain);
                            gain.connect(this.audio.destination);
                            const t = this.audio.currentTime + i * 0.35;
                            gain.gain.setValueAtTime(0.25, t);
                            gain.gain.exponentialRampToValueAtTime(0.001, t + 0.3);
                            osc.start(t);
                            osc.stop(t + 0.3);
                        });
                    },
                };
            }
        </script>
    @endpush
</x-layouts.public>
