@php
    /** @var \App\Models\Content|null $content */
    $content = $content ?? null;
    $selected = old('social_account_ids', $content?->schedules->pluck('social_account_id')->all() ?? []);
@endphp

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

    {{-- Main column --}}
    <div class="space-y-6 lg:col-span-2">

        <div class="card p-5">
            <p class="mb-4 text-sm font-semibold text-slate-700 dark:text-slate-200">Isi Konten</p>

            {{-- Title --}}
            <div>
                <label for="title" class="label">Judul <span class="text-rose-500">*</span></label>
                <input id="title" name="title" type="text" required maxlength="180"
                       value="{{ old('title', $content?->title) }}"
                       class="input @error('title') border-rose-400 @enderror"
                       placeholder="Misal: Promo Ramadan — Feed 1">
                @error('title') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            {{-- Caption --}}
            <div class="mt-4" x-data="{ text: @js(old('caption', $content?->caption ?? '')) }">
                <div class="flex items-center justify-between">
                    <label for="caption" class="label">Caption</label>
                    <span class="text-xs text-slate-400" x-text="`${text.length} / 2200`"></span>
                </div>
                <textarea id="caption" name="caption" rows="6" maxlength="2200" x-model="text"
                          class="input @error('caption') border-rose-400 @enderror"
                          placeholder="Tulis caption yang akan tampil di postingan…"></textarea>
                @error('caption') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            {{-- Hashtags --}}
            <div class="mt-4">
                <label for="hashtags" class="label">Hashtag</label>
                <input id="hashtags" name="hashtags" type="text" maxlength="600"
                       value="{{ old('hashtags', $content?->hashtags) }}"
                       class="input @error('hashtags') border-rose-400 @enderror"
                       placeholder="promo ramadan diskon">
                <p class="mt-1 text-xs text-slate-400">Pisahkan dengan spasi — tanda # ditambahkan otomatis.</p>
                @error('hashtags') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="mention" class="label">Mention</label>
                    <input id="mention" name="mention" type="text" value="{{ old('mention', $content?->mention) }}"
                           class="input" placeholder="@akun_partner">
                </div>
                <div>
                    <label for="location" class="label">Lokasi</label>
                    <input id="location" name="location" type="text" value="{{ old('location', $content?->location) }}"
                           class="input" placeholder="Jakarta, Indonesia">
                </div>
            </div>
        </div>

        {{-- Media --}}
        <div class="card p-5" x-data="mediaPicker()">
            <div class="mb-4 flex items-center justify-between">
                <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">Media</p>
                <span class="badge-slate">Rasio 4:5 – 1.91:1</span>
            </div>

            {{-- Spec panel: the rules, stated before anything is uploaded --}}
            <div class="mb-4 rounded-xl border border-slate-200 bg-slate-50/60 p-3 text-xs dark:border-white/10 dark:bg-white/[0.02]">
                <p class="mb-2 flex items-center gap-1.5 font-semibold text-slate-600 dark:text-slate-300">
                    <x-icon name="alert" class="h-3.5 w-3.5"/> Ketentuan media
                </p>
                <div class="grid grid-cols-1 gap-x-6 gap-y-1 text-slate-500 dark:text-slate-400 sm:grid-cols-2">
                    <p>📐 Rasio gambar <b>4:5</b> s/d <b>1.91:1</b></p>
                    <p>🖼️ Lebar minimal <b>320px</b></p>
                    <p>🎬 Video <b>3 detik – 15 menit</b></p>
                    <p>💾 Maksimal <b>100 MB</b> per file</p>
                    <p>📄 JPG, PNG, WebP, MP4, MOV</p>
                    <p>🔢 Maksimal <b>10 file</b></p>
                </div>
                <p class="mt-2 text-slate-500 dark:text-slate-400">
                    Ukuran disarankan: <b>1080×1080</b> (persegi) · <b>1080×1350</b> (potret) · <b>1200×628</b> (lanskap)
                </p>
            </div>

            {{-- Existing media (edit only) --}}
            @if ($content && $content->media->isNotEmpty())
                <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach ($content->media as $media)
                        <label class="group relative block cursor-pointer overflow-hidden rounded-xl border border-slate-200 dark:border-white/10">
                            <input type="checkbox" name="remove_media[]" value="{{ $media->id }}" class="peer sr-only">

                            @if ($media->isVideo())
                                <video class="h-24 w-full object-cover" muted preload="metadata">
                                    <source src="{{ $media->url() }}#t=0.1">
                                </video>
                            @else
                                <img src="{{ $media->url() }}" alt="" class="h-24 w-full object-cover">
                            @endif

                            <span class="absolute inset-0 grid place-items-center bg-rose-600/80 opacity-0 transition peer-checked:opacity-100">
                                <span class="text-xs font-bold text-white">Hapus</span>
                            </span>
                            <span class="absolute right-1 top-1 rounded-full bg-slate-900/70 px-1.5 py-0.5 text-[10px] text-white">
                                {{ $media->type->label() }}
                            </span>
                        </label>
                    @endforeach
                </div>
                <p class="mb-4 text-xs text-slate-400">Klik media untuk menandainya agar dihapus saat disimpan.</p>
            @endif

            {{-- Upload --}}
            <label class="flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed border-slate-200 px-6 py-8 text-center transition hover:border-brand-400 hover:bg-brand-50/40 dark:border-white/10 dark:hover:border-brand-500 dark:hover:bg-white/[0.02]">
                <input x-ref="input" type="file" name="media[]" multiple
                       accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime"
                       class="sr-only" @change="pick($event)">
                <x-icon name="upload" class="h-6 w-6 text-slate-400"/>
                <p class="mt-2 text-sm font-medium text-slate-600 dark:text-slate-300">Pilih gambar atau video</p>
                <p class="text-xs text-slate-400">Akan diperiksa otomatis setelah dipilih</p>
            </label>

            {{-- Summary alert --}}
            <template x-if="files.length && hasIssues">
                <p class="mt-3 flex items-start gap-2 rounded-xl border border-rose-200 bg-rose-50/60 p-3 text-xs text-rose-600 dark:border-rose-500/20 dark:bg-rose-500/5 dark:text-rose-400">
                    <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0"/>
                    <span>Ada file yang belum memenuhi ketentuan. Perbaiki dulu (gunakan tombol <b>Crop</b> untuk gambar) sebelum menyimpan.</span>
                </p>
            </template>

            {{-- Per-file preview with measurements --}}
            <template x-if="files.length">
                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <template x-for="(f, i) in files" :key="i">
                        <div class="flex gap-3 rounded-xl border p-2.5"
                             :class="f.ok
                                ? 'border-slate-200 dark:border-white/10'
                                : 'border-rose-300 bg-rose-50/40 dark:border-rose-500/30 dark:bg-rose-500/5'">

                            <div class="h-20 w-20 shrink-0 overflow-hidden rounded-lg bg-slate-100 dark:bg-white/5">
                                <img :src="f.url" x-show="!f.isVideo" class="h-full w-full object-cover" alt="">
                                <video :src="f.url" x-show="f.isVideo" class="h-full w-full object-cover" muted></video>
                            </div>

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-xs font-medium text-slate-700 dark:text-slate-200" x-text="f.name"></p>

                                <p class="mt-1 flex flex-wrap gap-1">
                                    <span class="badge-slate" x-text="`${f.width}×${f.height}`"></span>
                                    <span class="badge-slate" x-text="orientation(f)"></span>
                                    <span class="badge-slate" x-text="humanSize(f.size)"></span>
                                    <span class="badge-slate" x-show="f.isVideo" x-text="`${f.duration}s`"></span>
                                    <span class="badge-green" x-show="f.ok">Siap</span>
                                </p>

                                <template x-for="issue in f.issues" :key="issue">
                                    <p class="mt-1 text-[11px] text-rose-600 dark:text-rose-400" x-text="issue"></p>
                                </template>

                                <button type="button" x-show="!f.isVideo" @click="openCrop(i)"
                                        class="btn-outline btn-sm mt-2">
                                    <x-icon name="image" class="h-3 w-3"/> Crop
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            {{-- Cropper --}}
            <div x-show="cropIndex !== null" x-cloak
                 class="fixed inset-0 z-[95] flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="closeCrop()"></div>

                <div class="card relative w-full max-w-3xl p-5 shadow-2xl">
                    <div class="mb-3 flex items-center justify-between">
                        <p class="text-base font-semibold text-slate-800 dark:text-white">Sesuaikan Gambar</p>
                        <button type="button" @click="closeCrop()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                            <x-icon name="x" class="h-5 w-5"/>
                        </button>
                    </div>

                    <div class="mb-3 flex flex-wrap gap-2">
                        <template x-for="preset in spec.recommended" :key="preset.label">
                            <button type="button" @click="setRatio(preset.ratio)"
                                    class="btn-outline btn-sm"
                                    :class="Math.abs(cropRatio - preset.ratio) < 0.01 && '!border-brand-500 !text-brand-600'">
                                <span x-text="preset.label"></span>
                                <span class="opacity-60" x-text="preset.size"></span>
                            </button>
                        </template>
                    </div>

                    <div class="max-h-[55vh] overflow-hidden rounded-xl bg-slate-900/5 dark:bg-black/20">
                        <img x-ref="cropImage" class="block max-w-full" alt="">
                    </div>

                    <div class="mt-4 flex justify-end gap-2">
                        <button type="button" @click="closeCrop()" class="btn-outline">Batal</button>
                        <button type="button" @click="applyCrop()" class="btn-primary">
                            <x-icon name="check" class="h-4 w-4"/> Terapkan Crop
                        </button>
                    </div>
                </div>
            </div>

            @error('media') <p class="form-error">{{ $message }}</p> @enderror
            @foreach ($errors->get('media.*') as $messages)
                <p class="form-error">{{ $messages[0] }}</p>
            @endforeach
        </div>
    </div>

    {{-- Sidebar --}}
    <div class="space-y-6">

        {{-- Target accounts --}}
        <div class="card p-5">
            <p class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-200">Akun Tujuan <span class="text-rose-500">*</span></p>

            @forelse ($accounts as $account)
                <label class="row-hover -mx-2 flex cursor-pointer items-center gap-3 rounded-xl px-2 py-2">
                    <input type="checkbox" name="social_account_ids[]" value="{{ $account->id }}"
                           @checked(in_array($account->id, (array) $selected, true))
                           class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-white/20 dark:bg-ink-850">

                    <span class="grid h-8 w-8 place-items-center rounded-lg text-white"
                          style="background: {{ $account->platform->color() }}">
                        <x-icon :name="$account->platform->icon()" class="h-4 w-4"/>
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-slate-700 dark:text-slate-200">{{ $account->name }}</span>
                        <span class="block truncate text-xs text-slate-400">{{ $account->handle() }}</span>
                    </span>
                </label>
            @empty
                <x-empty-state icon="link" title="Belum ada akun" description="Hubungkan akun sosmed terlebih dahulu." class="!py-6"/>
            @endforelse

            @error('social_account_ids') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        {{-- Publish options --}}
        <div class="card p-5">
            <p class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-200">Opsi Posting</p>

            <div class="flex items-start gap-2.5 rounded-xl border border-slate-200 bg-slate-50/60 p-3 text-xs text-slate-500 dark:border-white/10 dark:bg-white/[0.02] dark:text-slate-400">
                <x-icon name="clock" class="mt-0.5 h-4 w-4 shrink-0 text-brand-500"/>
                <span>Jadwal terbit ditentukan oleh tim <b>Approval / Curator</b> saat konten disetujui.</span>
            </div>

            <label class="mt-4 flex cursor-pointer items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
                <input type="checkbox" name="is_carousel" value="1" @checked(old('is_carousel', $content?->is_carousel))
                       class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-white/20 dark:bg-ink-850">
                Posting sebagai carousel
            </label>
        </div>

        {{-- Internal note --}}
        <div class="card p-5">
            <label for="internal_note" class="label">Catatan Internal</label>
            <textarea id="internal_note" name="internal_note" rows="3" class="input"
                      placeholder="Catatan untuk tim — tidak ikut terposting.">{{ old('internal_note', $content?->internal_note) }}</textarea>
        </div>
    </div>
</div>

