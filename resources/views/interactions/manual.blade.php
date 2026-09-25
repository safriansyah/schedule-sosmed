<x-layouts.app title="Catat Interaksi Manual">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Catat Interaksi Manual</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Untuk kanal yang tidak bisa ditarik otomatis — DM TikTok, WhatsApp, dan sejenisnya.
            </p>
        </div>

        <a href="{{ route('interactions.index') }}" class="btn-outline">
            <x-icon name="chevron-left" class="h-4 w-4"/> Kembali
        </a>
    </x-slot:header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <form method="POST" action="{{ route('interactions.storeManual') }}" class="card space-y-4 p-5">
                @csrf

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="channel" class="label">Kanal <span class="text-rose-500">*</span></label>
                        <select id="channel" name="channel" class="input" required>
                            @foreach ($channels as $value => $label)
                                <option value="{{ $value }}" @selected(old('channel') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('channel') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="type" class="label">Jenis <span class="text-rose-500">*</span></label>
                        <select id="type" name="type" class="input" required>
                            @foreach ($types as $value => $label)
                                <option value="{{ $value }}" @selected(old('type', 'dm') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('type') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="author_handle" class="label">Username</label>
                        <input id="author_handle" name="author_handle" class="input"
                               value="{{ old('author_handle') }}" placeholder="tanpa tanda @">
                        @error('author_handle') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="phone" class="label">Nomor WhatsApp</label>
                        <input id="phone" name="phone" class="input"
                               value="{{ old('phone') }}" placeholder="0812xxxxxxx">
                        {{-- Either identifier is enough; the phone is what makes
                             the contact reachable for follow-up later. --}}
                        <p class="mt-1 text-[11px] text-slate-400">Isi salah satu — username atau nomor.</p>
                        @error('phone') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="author_name" class="label">Nama (jika tahu)</label>
                        <input id="author_name" name="author_name" class="input" value="{{ old('author_name') }}">
                    </div>

                    <div>
                        <label for="occurred_at" class="label">Waktu pesan masuk</label>
                        <input type="datetime-local" id="occurred_at" name="occurred_at" class="input"
                               value="{{ old('occurred_at') }}">
                        <p class="mt-1 text-[11px] text-slate-400">Kosongkan = sekarang.</p>
                        @error('occurred_at') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label for="text" class="label">Isi pesan <span class="text-rose-500">*</span></label>
                    <textarea id="text" name="text" rows="5" class="input" required
                              placeholder="Salin apa adanya — jangan diringkas, karena teks ini yang dinilai AI.">{{ old('text') }}</textarea>
                    @error('text') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <div class="flex gap-2">
                    <button class="btn-primary"><x-icon name="check" class="h-4 w-4"/> Simpan</button>
                    <a href="{{ route('interactions.index') }}" class="btn-outline">Batal</a>
                </div>
            </form>
        </div>

        <div class="space-y-4">
            <div class="card p-5">
                <h2 class="mb-3 flex items-center gap-2 text-sm font-bold text-slate-800 dark:text-white">
                    <x-icon name="help-circle" class="h-4 w-4 text-brand-500"/> Kenapa manual?
                </h2>
                <div class="space-y-3 text-sm text-slate-500 dark:text-slate-400">
                    <p>
                        TikTok tidak menyediakan API untuk membaca DM sama sekali, dan WhatsApp
                        memerlukan akun bisnis yang disetujui. Jadi untuk kanal ini, mengetik manual
                        bukan jalan pintas — memang itu caranya.
                    </p>
                    <p>
                        Setelah tersimpan, interaksi ini diperlakukan <strong>persis sama</strong> seperti
                        komentar yang ditarik otomatis: dicocokkan ke kontak, dinilai AI, masuk hitungan
                        SLA, dan bisa di-follow-up.
                    </p>
                </div>
            </div>

            <div class="card p-5">
                <h2 class="mb-3 text-sm font-bold text-slate-800 dark:text-white">Tips</h2>
                <ul class="space-y-2 text-sm text-slate-500 dark:text-slate-400">
                    <li class="flex gap-2">
                        <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-500"/>
                        Salin pesan apa adanya, termasuk salah ketik dan emoji.
                    </li>
                    <li class="flex gap-2">
                        <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-500"/>
                        Isi nomor WA kalau ada — itu yang membuat orangnya bisa dihubungi lagi.
                    </li>
                    <li class="flex gap-2">
                        <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-500"/>
                        Nomor boleh ditulis 0812…, +62812… atau 62812…
                    </li>
                    <li class="flex gap-2">
                        <x-icon name="x" class="mt-0.5 h-4 w-4 shrink-0 text-rose-500"/>
                        Jangan diringkas — AI menilai dari teks ini.
                    </li>
                </ul>
            </div>
        </div>
    </div>
</x-layouts.app>
