<x-layouts.public title="Buku Tamu / Antrian">
    <header class="mb-6 text-center sm:mb-8">
        <div class="mx-auto mb-3 grid h-14 w-14 place-items-center rounded-2xl bg-gradient-to-br from-brand-600 to-accent-500 text-white shadow-lg shadow-brand-600/25">
            <x-icon name="id-card" class="h-7 w-7"/>
        </div>
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-brand-600 dark:text-brand-300">{{ $branding->name() }}</p>
        <h1 class="mt-1 text-2xl font-extrabold tracking-tight text-slate-800 sm:text-3xl dark:text-white">Buku Tamu &amp; Antrian</h1>
        <p class="mx-auto mt-2 max-w-md text-sm text-slate-500 dark:text-slate-400">
            Isi data di bawah ini untuk mengambil nomor antrian layanan. Nomor Anda akan langsung tampil di layar monitor.
        </p>
    </header>

    <form method="POST" action="{{ route('guest-book.store') }}"
          x-data="{ busy: false }"
          @submit="if (! $el.querySelector('[name=signature]').value) { $event.preventDefault(); window.toast('Paraf wajib diisi — gambar paraf Anda pada kotak yang tersedia.', 'error'); return; } busy = true"
          class="overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-xl shadow-slate-200/50 dark:border-white/10 dark:bg-ink-900 dark:shadow-none">
        @csrf

        {{-- Honeypot. Hidden from people; bots that fill everything get no number. --}}
        <div class="hidden" aria-hidden="true">
            <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </div>

        {{-- The number is the system's to give, so it is shown, not asked for. --}}
        <div class="flex items-center justify-between gap-3 border-b border-slate-100 bg-gradient-to-r from-brand-600/[0.06] to-transparent px-5 py-4 sm:px-7 dark:border-white/5">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">No. Antrian</p>
                <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">Dibuat otomatis setelah Anda menekan Simpan</p>
            </div>
            <span class="grid h-12 min-w-12 place-items-center rounded-2xl border-2 border-dashed border-brand-300 px-3 font-mono text-lg font-bold text-brand-500 dark:border-brand-500/40">#</span>
        </div>

        <div class="space-y-5 p-5 sm:p-7">
            @if ($errors->any())
                <div class="rounded-2xl border border-rose-500/30 bg-rose-500/[0.06] p-3 text-sm text-rose-600 dark:text-rose-400">
                    <p class="font-semibold">Periksa kembali isian Anda:</p>
                    <ul class="mt-1 list-inside list-disc text-xs">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div>
                <label for="name" class="label">Nama lengkap <span class="text-rose-500">*</span></label>
                <input id="name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name"
                       class="input" placeholder="Nama sesuai identitas">
                @error('name') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="whatsapp" class="label">No. WhatsApp aktif <span class="text-rose-500">*</span></label>
                    <input id="whatsapp" name="whatsapp" value="{{ old('whatsapp') }}" required maxlength="20"
                           inputmode="tel" autocomplete="tel" class="input" placeholder="0812 3456 7890">
                    @error('whatsapp') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div x-data="{ same: false }">
                    <label for="phone" class="label">No. HP aktif <span class="text-rose-500">*</span></label>
                    <input id="phone" name="phone" value="{{ old('phone') }}" required maxlength="20"
                           inputmode="tel" class="input" placeholder="0812 3456 7890"
                           x-ref="phone" :readonly="same">
                    <label class="mt-1.5 flex cursor-pointer items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                        <input type="checkbox" x-model="same"
                               @change="if (same) $refs.phone.value = document.getElementById('whatsapp').value"
                               class="h-3.5 w-3.5 rounded border-slate-300 text-brand-600">
                        Sama dengan No. WhatsApp
                    </label>
                    @error('phone') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="nim" class="label">NIM <span class="text-xs font-normal text-slate-400">(jika ada)</span></label>
                    <input id="nim" name="nim" value="{{ old('nim') }}" maxlength="20" inputmode="numeric"
                           class="input" placeholder="Contoh: 012345678">
                    @error('nim') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <p class="label">Jenis kelamin <span class="text-rose-500">*</span></p>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach ($genders as $value => $label)
                            <label class="flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-slate-200 px-3 py-2.5 text-sm font-medium text-slate-600 transition has-[:checked]:border-brand-500 has-[:checked]:bg-brand-500/[0.08] has-[:checked]:text-brand-700 dark:border-white/10 dark:text-slate-300 dark:has-[:checked]:text-brand-300">
                                <input type="radio" name="gender" value="{{ $value }}" required @checked(old('gender') === $value) class="sr-only">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    @error('gender') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <p class="label">Jenis layanan <span class="text-rose-500">*</span></p>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($services as $value => $label)
                        <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 px-3.5 py-3 text-[13px] font-semibold leading-snug text-slate-600 transition hover:border-brand-300 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-500/[0.08] has-[:checked]:text-brand-700 dark:border-white/10 dark:text-slate-300 dark:has-[:checked]:text-brand-300">
                            <input type="radio" name="service" value="{{ $value }}" required @checked(old('service') === $value)
                                   class="h-4 w-4 shrink-0 border-slate-300 text-brand-600 focus:ring-brand-500/40">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('service') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="description" class="label">Deskripsi <span class="text-xs font-normal text-slate-400">(opsional)</span></label>
                <textarea id="description" name="description" rows="3" maxlength="2000" class="input"
                          placeholder="Ceritakan singkat keperluan Anda">{{ old('description') }}</textarea>
                @error('description') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            {{-- Paraf: drawn with a finger, stylus or mouse. --}}
            <div x-data="signaturePad()">
                <div class="mb-1.5 flex items-center justify-between">
                    <p class="label !mb-0">Paraf <span class="text-rose-500">*</span></p>
                    <button type="button" @click="clear()" class="-my-2 rounded-lg px-2 py-2 text-xs font-semibold text-brand-600 hover:bg-brand-500/10 dark:text-brand-300">
                        Hapus paraf
                    </button>
                </div>
                <div class="relative overflow-hidden rounded-2xl border-2 border-dashed border-slate-300 bg-slate-50 dark:border-white/15 dark:bg-white/[0.03]"
                     :class="empty ? '' : '!border-solid !border-brand-400'">
                    <canvas x-ref="canvas" class="block h-40 w-full touch-none cursor-crosshair"></canvas>
                    <p x-show="empty" class="pointer-events-none absolute inset-0 grid place-items-center text-sm text-slate-400">
                        Gambar paraf Anda di sini
                    </p>
                </div>
                <input type="hidden" name="signature" x-ref="output">
                @error('signature') <p class="form-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="border-t border-slate-100 bg-slate-50/60 p-5 sm:px-7 dark:border-white/5 dark:bg-white/[0.02]">
            <button class="btn-primary w-full !py-3 text-base" :disabled="busy">
                <x-icon name="check" class="h-5 w-5" x-show="!busy"/>
                <x-icon name="refresh" class="h-5 w-5 animate-spin" x-show="busy" x-cloak/>
                <span x-text="busy ? 'Menyimpan…' : 'Simpan & Ambil Nomor Antrian'">Simpan &amp; Ambil Nomor Antrian</span>
            </button>
            <p class="mt-3 text-center text-xs text-slate-400">
                <span class="text-rose-500">*</span> wajib diisi ·
                <a href="{{ route('guest-book.monitor') }}" class="font-semibold text-brand-600 hover:underline dark:text-brand-300">Lihat monitor antrian</a>
            </p>
        </div>
    </form>

    @push('scripts')
        <script>
            function signaturePad() {
                return {
                    empty: true,
                    drawing: false,
                    ctx: null,

                    init() {
                        const canvas = this.$refs.canvas;
                        this.ctx = canvas.getContext('2d');
                        this.resize();
                        window.addEventListener('resize', () => this.empty && this.resize());

                        canvas.addEventListener('pointerdown', (e) => {
                            this.drawing = true;
                            canvas.setPointerCapture(e.pointerId);
                            this.ctx.beginPath();
                            this.ctx.moveTo(...this.point(e));
                        });
                        canvas.addEventListener('pointermove', (e) => {
                            if (!this.drawing) return;
                            this.ctx.lineTo(...this.point(e));
                            this.ctx.stroke();
                            this.empty = false;
                        });
                        ['pointerup', 'pointercancel', 'pointerleave'].forEach((type) =>
                            canvas.addEventListener(type, () => { this.drawing = false; this.save(); }));

                    },

                    resize() {
                        const canvas = this.$refs.canvas;
                        const ratio = Math.min(window.devicePixelRatio || 1, 2);
                        canvas.width = canvas.offsetWidth * ratio;
                        canvas.height = canvas.offsetHeight * ratio;
                        this.ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
                        this.ctx.lineWidth = 2.2;
                        this.ctx.lineCap = 'round';
                        this.ctx.lineJoin = 'round';
                        this.ctx.strokeStyle = '#1e1b4b';
                    },

                    point(e) {
                        const rect = this.$refs.canvas.getBoundingClientRect();
                        return [e.clientX - rect.left, e.clientY - rect.top];
                    },

                    save() {
                        this.$refs.output.value = this.empty ? '' : this.$refs.canvas.toDataURL('image/png');
                    },

                    clear() {
                        this.ctx.clearRect(0, 0, this.$refs.canvas.width, this.$refs.canvas.height);
                        this.empty = true;
                        this.save();
                    },
                };
            }
        </script>
    @endpush
</x-layouts.public>
