<x-layouts.app title="Verifikasi">
    <x-slot:header>
        <div>
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Antrean Verifikasi</h1>
            <p class="mt-0.5 text-sm text-slate-400">{{ $contents->total() }} konten menunggu pengecekan akhir.</p>
        </div>
    </x-slot:header>

    @forelse ($contents as $content)
        @php $cover = $content->media->first(); @endphp

        <div class="card mb-4 overflow-hidden" x-data="{ open: false, detailOpen: false }">
            <div class="flex flex-col gap-4 p-5 sm:flex-row">

                <button type="button" @click="detailOpen = true"
                        class="group h-32 w-full shrink-0 overflow-hidden rounded-xl bg-slate-100 sm:w-32 dark:bg-white/5">
                    @if ($cover)
                        @if ($cover->isVideo())
                            <video class="h-full w-full object-cover transition group-hover:scale-105" muted preload="metadata">
                                <source src="{{ $cover->url() }}#t=0.1">
                            </video>
                        @else
                            <img src="{{ $cover->url() }}" alt="" class="h-full w-full object-cover transition group-hover:scale-105">
                        @endif
                    @else
                        <div class="grid h-full place-items-center text-slate-300"><x-icon name="image" class="h-6 w-6"/></div>
                    @endif
                </button>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" @click="detailOpen = true"
                                class="truncate text-left text-sm font-semibold text-slate-800 transition hover:text-brand-600 dark:text-white">
                            {{ $content->title }}
                        </button>
                        <x-status-badge :status="$content->status"/>
                    </div>

                    <p class="mt-1.5 line-clamp-2 text-sm text-slate-400">{{ $content->caption ?: 'Tanpa caption' }}</p>

                    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-400">
                        <span>Creator: {{ $content->creator?->name ?? '-' }}</span>
                        <span>Disetujui: {{ $content->curator?->name ?? '-' }}</span>
                        <span class="flex items-center gap-1">
                            <x-icon name="calendar" class="h-3 w-3"/>
                            {{ $content->scheduled_at?->translatedFormat('d M Y, H:i') ?? 'Belum dijadwal' }}
                        </span>
                    </div>
                </div>

                @can('decideVerification', $content)
                    <button type="button" @click="open = true" class="btn-primary btn-sm shrink-0 self-start">
                        <x-icon name="badge-check" class="h-3.5 w-3.5"/> Beri Keputusan
                    </button>
                @else
                    <span class="badge-slate shrink-0 self-start">Hanya lihat</span>
                @endcan
            </div>

            {{-- Verification modal --}}
            @can('decideVerification', $content)
                <x-decision-modal :title="'Verifikasi: '.$content->title" :action="route('verifications.store', $content)">
                    <p class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-200">Checklist pengecekan akhir</p>

                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        @foreach ($checklist as $key => $label)
                            <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm transition hover:bg-white dark:border-white/10 dark:hover:bg-white/5">
                                <input type="checkbox" name="checklist[{{ $key }}]" value="1"
                                       class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-white/20 dark:bg-ink-850">
                                <span class="text-slate-600 dark:text-slate-300">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>

                    <div class="mt-4">
                        <label class="label">Catatan <span class="text-slate-400">(wajib untuk revisi)</span></label>
                        <textarea name="note" rows="3" class="input"
                                  placeholder="Catatan hasil verifikasi…">{{ old('note') }}</textarea>
                        @error('note') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <x-slot:actions>
                        <button type="submit" name="action" value="approved" class="btn-success btn-sm">
                            <x-icon name="badge-check" class="h-3.5 w-3.5"/> Verifikasi
                        </button>
                        <button type="submit" name="action" value="revision" class="btn-outline btn-sm"
                                @click="if (!$root.querySelector('[name=note]').value.trim()) { $event.preventDefault(); $root.querySelector('[name=note]').focus(); }">
                            <x-icon name="rotate" class="h-3.5 w-3.5"/> Minta Revisi
                        </button>
                    </x-slot:actions>
                </x-decision-modal>
            @endcan

            {{-- Content preview modal (opened by clicking the title/thumbnail) --}}
            <x-content-preview-modal :content="$content"/>
        </div>
    @empty
        <div class="card">
            <x-empty-state icon="badge-check" title="Antrean kosong"
                           description="Tidak ada konten yang menunggu verifikasi saat ini."/>
        </div>
    @endforelse

    @if ($contents->hasPages())
        <div class="mt-6">{{ $contents->links() }}</div>
    @endif

    <x-decision-history :decisions="$history"/>
</x-layouts.app>
