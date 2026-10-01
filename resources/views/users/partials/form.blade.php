@php $user = $user ?? null; @endphp

<div class="mx-auto max-w-2xl space-y-6">

    <div class="card p-6">
        <p class="mb-4 text-sm font-semibold text-slate-700 dark:text-slate-200">Identitas</p>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label for="name" class="label">Nama <span class="text-rose-500">*</span></label>
                <input id="name" name="name" required maxlength="120" value="{{ old('name', $user?->name) }}"
                       class="input @error('name') border-rose-400 @enderror" placeholder="Nama lengkap">
                @error('name') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="phone" class="label">Telepon</label>
                <input id="phone" name="phone" maxlength="32" value="{{ old('phone', $user?->phone) }}"
                       class="input" placeholder="08xxxxxxxxxx">
            </div>
        </div>

        <div class="mt-4">
            <label for="email" class="label">Email <span class="text-rose-500">*</span></label>
            <input id="email" name="email" type="email" required value="{{ old('email', $user?->email) }}"
                   class="input @error('email') border-rose-400 @enderror" placeholder="nama@perusahaan.com">
            @error('email') <p class="form-error">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="card p-6">
        <p class="mb-4 text-sm font-semibold text-slate-700 dark:text-slate-200">Hak Akses</p>

        <label class="label">Role <span class="text-rose-500">*</span></label>
        <div class="space-y-2">
            @foreach ($roles as $role)
                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-3 transition hover:bg-slate-50 dark:border-white/10 dark:hover:bg-white/5">
                    <input type="radio" name="role_id" value="{{ $role->id }}" required
                           @checked((int) old('role_id', $user?->role_id) === $role->id)
                           class="mt-0.5 h-4 w-4 border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-white/20 dark:bg-ink-850">
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-1.5 text-sm font-medium text-slate-700 dark:text-slate-200">
                            <x-icon :name="$role->name->icon()" class="h-4 w-4 text-slate-400"/>
                            {{ $role->label }}
                        </span>
                        <span class="mt-0.5 block text-xs text-slate-400">{{ $role->description }}</span>
                    </span>
                </label>
            @endforeach
        </div>
        @error('role_id') <p class="form-error">{{ $message }}</p> @enderror

        {{-- Status pengguna. Disable keeps the account, its history and its
             assignments, but it cannot log in, and an open session is signed
             out on its next request. --}}
        @php
            $active = (bool) old('is_active', $user?->is_active ?? true);
            $self = $user && $user->is(auth()->user());
        @endphp

        <p class="label mt-5">Status pengguna</p>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-3 transition has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-500/[0.06] dark:border-white/10">
                <input type="radio" name="is_active" value="1" @checked($active) class="mt-0.5 text-emerald-600 focus:ring-emerald-500/40">
                <span>
                    <span class="flex items-center gap-1.5 text-sm font-semibold text-slate-700 dark:text-slate-200">
                        <x-icon name="check-circle" class="h-4 w-4 text-emerald-500"/> Aktif
                    </span>
                    <span class="mt-0.5 block text-xs text-slate-400">Bisa login dan bekerja seperti biasa.</span>
                </span>
            </label>

            <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-3 transition has-[:checked]:border-rose-500 has-[:checked]:bg-rose-500/[0.06] dark:border-white/10 {{ $self ? 'cursor-not-allowed opacity-50' : 'cursor-pointer' }}">
                <input type="radio" name="is_active" value="0" @checked(! $active) @disabled($self) class="mt-0.5 text-rose-600 focus:ring-rose-500/40">
                <span>
                    <span class="flex items-center gap-1.5 text-sm font-semibold text-slate-700 dark:text-slate-200">
                        <x-icon name="ban" class="h-4 w-4 text-rose-500"/> Disable
                    </span>
                    <span class="mt-0.5 block text-xs text-slate-400">Akun tetap ada, tapi tidak bisa login sama sekali.</span>
                </span>
            </label>
        </div>
        @if ($self)
            <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">Anda tidak dapat men-disable akun sendiri.</p>
        @endif
    </div>

    <div class="card p-6">
        <p class="mb-1 text-sm font-semibold text-slate-700 dark:text-slate-200">Kata Sandi</p>
        <p class="mb-4 text-xs text-slate-400">
            {{ $user ? 'Kosongkan bila tidak ingin mengubah kata sandi.' : 'Minimal 8 karakter, mengandung huruf dan angka.' }}
        </p>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label for="password" class="label">Kata sandi @unless ($user) <span class="text-rose-500">*</span> @endunless</label>
                <input id="password" name="password" type="password" autocomplete="new-password"
                       class="input @error('password') border-rose-400 @enderror" placeholder="••••••••">
                @error('password') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password_confirmation" class="label">Ulangi kata sandi</label>
                <input id="password_confirmation" name="password_confirmation" type="password"
                       autocomplete="new-password" class="input" placeholder="••••••••">
            </div>
        </div>

        {{-- Said out loud because there is no way to tell from the form that a
             pasted hash was understood as a hash rather than stored as the
             literal password. --}}
        <p class="mt-3 flex items-start gap-2 rounded-xl bg-slate-50 p-3 text-[11px] text-slate-500 dark:bg-white/[0.03] dark:text-slate-400">
            <x-icon name="shield" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400"/>
            <span>
                Boleh juga menempel <span class="font-semibold">hash bcrypt</span> yang sudah jadi
                (awalan <span class="font-mono">$2y$</span>, <span class="font-mono">$2a$</span>,
                atau <span class="font-mono">$2b$</span>, tepat 60 karakter) — disimpan apa adanya,
                tidak di-hash dua kali. Tempel di kedua kolom supaya cocok.
                Kalau tertempel sebagian, form akan menolak, bukan menyimpannya diam-diam.
            </span>
        </p>
    </div>

    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <a href="{{ route('users.index') }}" class="btn-outline">Batal</a>
        <button type="submit" class="btn-primary">
            <x-icon name="check" class="h-4 w-4"/> Simpan
        </button>
    </div>
</div>
