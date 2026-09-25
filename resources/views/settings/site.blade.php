<x-layouts.app title="Identitas Website">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Identitas Website</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Nama, logo, dan favicon yang dipakai di seluruh halaman.
            </p>
        </div>
    </x-slot:header>

    <form method="POST" action="{{ route('settings.site.update') }}" enctype="multipart/form-data"
          class="grid gap-6 lg:grid-cols-3">
        @csrf
        @method('PUT')

        <div class="card p-5 lg:col-span-2">
            <div class="space-y-5">
                <div>
                    <label for="name" class="label">Nama website <span class="text-rose-500">*</span></label>
                    <input id="name" name="name" value="{{ old('name', $name) }}" class="input" required maxlength="64">
                    @error('name') <p class="form-error">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-slate-400">
                        Muncul di judul tab, sidebar, dan halaman login.
                        Nilai bawaan dari berkas konfigurasi: <span class="font-medium">{{ $configName }}</span>.
                    </p>
                </div>

                <div>
                    <label for="tagline" class="label">Tagline</label>
                    <input id="tagline" name="tagline" value="{{ old('tagline', $tagline) }}" class="input" maxlength="96">
                    @error('tagline') <p class="form-error">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-slate-400">Baris kecil di bawah nama pada sidebar.</p>
                </div>

                {{-- Logo and favicon side by side: they are the same decision
                     made twice, and seeing both previews together is how you
                     notice one of them is wrong. --}}
                <div class="grid gap-5 border-t border-slate-200 pt-5 sm:grid-cols-2 dark:border-white/5">
                    @foreach ([
                        ['key' => 'logo', 'label' => 'Logo', 'url' => $logoUrl,
                         'hint' => 'PNG/JPG/WebP, maksimum 2 MB. Disarankan persegi atau lebar, latar transparan.',
                         'box' => 'h-16 w-16'],
                        ['key' => 'favicon', 'label' => 'Favicon', 'url' => $faviconUrl,
                         'hint' => 'PNG/ICO/WebP, maksimum 512 KB. Disarankan 32×32 atau 64×64.',
                         'box' => 'h-10 w-10'],
                    ] as $field)
                        <div>
                            <p class="label">{{ $field['label'] }}</p>

                            <div class="flex items-start gap-3">
                                <span class="grid {{ $field['box'] }} shrink-0 place-items-center overflow-hidden rounded-xl border border-slate-200 bg-slate-50 dark:border-white/10 dark:bg-white/5">
                                    @if ($field['url'])
                                        <img src="{{ $field['url'] }}" alt="{{ $field['label'] }}" class="h-full w-full object-contain">
                                    @else
                                        <x-icon name="image" class="h-4 w-4 text-slate-300"/>
                                    @endif
                                </span>

                                <div class="min-w-0 flex-1">
                                    <input id="{{ $field['key'] }}" name="{{ $field['key'] }}" type="file"
                                           accept="image/*" class="input !py-1.5 !text-xs">
                                    @error($field['key']) <p class="form-error">{{ $message }}</p> @enderror

                                    @if ($field['url'])
                                        <label class="mt-2 flex cursor-pointer items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                                            <input type="checkbox" name="remove_{{ $field['key'] }}" value="1"
                                                   class="h-3.5 w-3.5 rounded border-slate-300 text-rose-600 focus:ring-rose-500/40 dark:border-white/20 dark:bg-ink-850">
                                            Hapus {{ strtolower($field['label']) }} saat ini
                                        </label>
                                    @endif
                                </div>
                            </div>

                            <p class="mt-1.5 text-[11px] text-slate-400">{{ $field['hint'] }}</p>
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-wrap gap-2 border-t border-slate-200 pt-5 dark:border-white/5">
                    <button class="btn-primary"><x-icon name="check" class="h-4 w-4"/> Simpan Identitas</button>
                    <a href="{{ route('settings.edit') }}" class="btn-outline">Pengaturan Akun</a>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            {{-- A preview of the sidebar brand block, because that is where the
                 logo and name actually appear together and where a wrong size
                 shows up first. --}}
            <div class="card p-5">
                <h2 class="mb-3 text-base font-bold text-slate-800 dark:text-white">Pratinjau sidebar</h2>

                <div class="flex items-center gap-3 rounded-xl border border-slate-200 p-3 dark:border-white/10">
                    <span class="grid h-10 w-10 shrink-0 place-items-center overflow-hidden rounded-xl bg-gradient-to-br from-brand-600 to-brand-500 text-white shadow-lg">
                        @if ($logoUrl)
                            <img src="{{ $logoUrl }}" alt="" class="h-full w-full object-cover">
                        @else
                            <x-icon name="send" class="h-5 w-5"/>
                        @endif
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-bold text-slate-800 dark:text-white">{{ old('name', $name) }}</p>
                        <p class="truncate text-[11px] text-slate-400">{{ old('tagline', $tagline) }}</p>
                    </div>
                </div>

                <p class="mt-3 text-[11px] text-slate-400">
                    Tanpa logo, lambang bawaan yang dipakai — bukan kotak kosong.
                </p>
            </div>

            <div class="card p-4">
                <p class="flex items-start gap-2 text-xs text-slate-500 dark:text-slate-400">
                    <x-icon name="help-circle" class="mt-0.5 h-4 w-4 shrink-0 text-slate-400"/>
                    <span>
                        Berkas disimpan di penyimpanan publik, jadi butuh
                        <span class="font-mono">php artisan storage:link</span> sekali saja agar bisa diakses.
                        Favicon di-cache browser cukup lama — muat ulang dengan Ctrl+Shift+R kalau belum berubah.
                    </span>
                </p>
            </div>
        </div>
    </form>
</x-layouts.app>
