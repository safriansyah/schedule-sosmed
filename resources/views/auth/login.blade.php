<x-layouts.guest title="Masuk">
    <form method="POST" action="{{ route('login') }}" class="card p-7 shadow-xl">
        @csrf

        <h2 class="text-lg font-bold text-slate-800 dark:text-white">Masuk ke akun Anda</h2>
        <p class="mt-1 text-sm text-slate-400">Kelola konten dan jadwal media sosial Anda.</p>

        {{-- Email --}}
        <div class="mt-6">
            <label for="email" class="label">Email</label>
            <input id="email" name="email" type="email" inputmode="email" autocomplete="email"
                   value="{{ old('email') }}" required autofocus
                   class="input @error('email') border-rose-400 focus:border-rose-400 focus:ring-rose-500/10 @enderror"
                   placeholder="nama@perusahaan.com">
            @error('email')
                <p class="form-error">{{ $message }}</p>
            @enderror
        </div>

        {{-- Password --}}
        <div class="mt-4" x-data="{ show: false }">
            <label for="password" class="label">Kata sandi</label>
            <div class="relative">
                <input id="password" name="password" :type="show ? 'text' : 'password'"
                       autocomplete="current-password" required
                       class="input pr-11 @error('password') border-rose-400 focus:border-rose-400 focus:ring-rose-500/10 @enderror"
                       placeholder="••••••••">
                <button type="button" @click="show = !show" tabindex="-1"
                        class="absolute inset-y-0 right-0 grid w-11 place-items-center text-slate-400 transition hover:text-slate-600 dark:hover:text-slate-200"
                        :aria-label="show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'">
                    <x-icon name="eye" class="h-4 w-4"/>
                </button>
            </div>
            @error('password')
                <p class="form-error">{{ $message }}</p>
            @enderror
        </div>

        {{-- Remember --}}
        <label class="mt-4 flex cursor-pointer items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
            <input type="checkbox" name="remember" value="1"
                   class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-white/20 dark:bg-ink-850">
            Ingat saya
        </label>

        <button type="submit" class="btn-primary mt-6 w-full">
            <x-icon name="logout" class="h-4 w-4 rotate-180"/>
            Masuk
        </button>
    </form>
</x-layouts.guest>
