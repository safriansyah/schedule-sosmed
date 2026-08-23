@php $account = $account ?? null; @endphp

<div class="card mx-auto max-w-2xl p-6">
    <div>
        <label for="platform" class="label">Platform <span class="text-rose-500">*</span></label>
        <select id="platform" name="platform" class="input" required>
            @foreach ($platforms as $platform)
                <option value="{{ $platform->value }}"
                        @selected(old('platform', $account?->platform?->value) === $platform->value)>
                    {{ $platform->label() }}{{ $platform->isImplemented() ? '' : ' — belum didukung' }}
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-slate-400">Saat ini publikasi otomatis baru tersedia untuk Instagram.</p>
        @error('platform') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <label for="name" class="label">Nama Akun <span class="text-rose-500">*</span></label>
            <input id="name" name="name" required maxlength="120"
                   value="{{ old('name', $account?->name) }}" class="input" placeholder="Instagram Utama">
            @error('name') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="username" class="label">Username</label>
            <input id="username" name="username" maxlength="120"
                   value="{{ old('username', $account?->username) }}" class="input" placeholder="nama_akun">
            <p class="mt-1 text-xs text-slate-400">Terisi otomatis setelah tes koneksi.</p>
        </div>
    </div>

    <div class="mt-4">
        <label for="access_token" class="label">
            Access Token @unless ($account) <span class="text-rose-500">*</span> @endunless
        </label>
        <textarea id="access_token" name="access_token" rows="3" class="input font-mono text-xs"
                  placeholder="{{ $account ? 'Biarkan kosong untuk mempertahankan token saat ini' : 'IGAA…' }}"
                  @unless ($account) required @endunless></textarea>
        <p class="mt-1 text-xs text-slate-400">
            Token disimpan terenkripsi dan tidak pernah ditampilkan kembali.
            @if ($account?->access_token)
                <span class="badge-green ml-1">Token tersimpan</span>
            @endif
        </p>
        @error('access_token') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <label class="mt-4 flex cursor-pointer items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $account?->is_active ?? true))
               class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-white/20 dark:bg-ink-850">
        Aktif — sertakan akun ini sebagai tujuan publikasi
    </label>

    <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <a href="{{ route('accounts.index') }}" class="btn-outline">Batal</a>
        <button type="submit" class="btn-primary">
            <x-icon name="check" class="h-4 w-4"/> Simpan &amp; Tes Koneksi
        </button>
    </div>
</div>
