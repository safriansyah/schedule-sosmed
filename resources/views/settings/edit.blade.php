<x-layouts.app title="Pengaturan">
    <x-slot:header>
        <div>
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Pengaturan</h1>
            <p class="mt-0.5 text-sm text-slate-400">Kelola profil dan keamanan akun Anda.</p>
        </div>
    </x-slot:header>

    <div class="mx-auto max-w-2xl space-y-6">

        {{-- Account summary --}}
        <div class="card flex flex-col gap-4 p-6 sm:flex-row sm:items-center">
            <span class="avatar h-16 w-16 shrink-0 text-xl">{{ $user->initial() }}</span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-base font-bold text-slate-800 dark:text-white">{{ $user->name }}</p>
                <p class="truncate text-sm text-slate-400">{{ $user->email }}</p>
                <p class="mt-1.5">
                    <span class="badge-blue">
                        <x-icon :name="$user->role?->name?->icon() ?? 'users'" class="h-3 w-3"/>
                        {{ $user->roleLabel() }}
                    </span>
                </p>
            </div>
        </div>

        {{-- Profile --}}
        <form method="POST" action="{{ route('settings.profile') }}" class="card p-6">
            @csrf
            @method('PUT')

            <p class="mb-4 text-sm font-semibold text-slate-700 dark:text-slate-200">Profil</p>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="name" class="label">Nama</label>
                    <input id="name" name="name" required maxlength="120" value="{{ old('name', $user->name) }}"
                           class="input @error('name') border-rose-400 @enderror">
                    @error('name') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="phone" class="label">Telepon</label>
                    <input id="phone" name="phone" maxlength="32" value="{{ old('phone', $user->phone) }}" class="input">
                </div>
            </div>

            <div class="mt-4">
                <label for="email" class="label">Email</label>
                <input id="email" name="email" type="email" required value="{{ old('email', $user->email) }}"
                       class="input @error('email') border-rose-400 @enderror">
                @error('email') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div class="mt-5 flex justify-end">
                <button class="btn-primary"><x-icon name="check" class="h-4 w-4"/> Simpan Profil</button>
            </div>
        </form>

        {{-- Password --}}
        <form method="POST" action="{{ route('settings.password') }}" class="card p-6">
            @csrf
            @method('PUT')

            <p class="mb-1 text-sm font-semibold text-slate-700 dark:text-slate-200">Ganti Kata Sandi</p>
            <p class="mb-4 text-xs text-slate-400">Minimal 8 karakter, mengandung huruf dan angka.</p>

            <div>
                <label for="current_password" class="label">Kata sandi saat ini</label>
                <input id="current_password" name="current_password" type="password" autocomplete="current-password"
                       class="input @error('current_password') border-rose-400 @enderror">
                @error('current_password') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="password" class="label">Kata sandi baru</label>
                    <input id="password" name="password" type="password" autocomplete="new-password"
                           class="input @error('password') border-rose-400 @enderror">
                    @error('password') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="password_confirmation" class="label">Ulangi kata sandi baru</label>
                    <input id="password_confirmation" name="password_confirmation" type="password"
                           autocomplete="new-password" class="input">
                </div>
            </div>

            <div class="mt-5 flex justify-end">
                <button class="btn-primary"><x-icon name="shield" class="h-4 w-4"/> Ubah Kata Sandi</button>
            </div>
        </form>

        {{-- Appearance --}}
        <div class="card p-6">
            <p class="mb-1 text-sm font-semibold text-slate-700 dark:text-slate-200">Tampilan</p>
            <p class="mb-4 text-xs text-slate-400">Preferensi tema disimpan di peramban ini.</p>

            <div class="flex gap-3">
                <button type="button" @click="if ($store.theme.dark) $store.theme.toggle()"
                        class="btn-outline flex-1"
                        :class="!$store.theme.dark && '!border-brand-500 !text-brand-600'">
                    <x-icon name="sun" class="h-4 w-4"/> Terang
                </button>

                <button type="button" @click="if (!$store.theme.dark) $store.theme.toggle()"
                        class="btn-outline flex-1"
                        :class="$store.theme.dark && '!border-brand-500 !text-brand-600'">
                    <x-icon name="moon" class="h-4 w-4"/> Gelap
                </button>
            </div>
        </div>
    </div>
</x-layouts.app>
