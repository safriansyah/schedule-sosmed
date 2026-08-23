<x-layouts.app title="Approval">
    <x-slot:header>
        <div>
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Antrean Approval</h1>
            <p class="mt-0.5 text-sm text-slate-400">{{ $contents->total() }} konten menunggu keputusan Anda.</p>
        </div>
    </x-slot:header>

    @forelse ($contents as $content)
        @php $cover = $content->media->first(); @endphp

        <div class="card mb-4 overflow-hidden" x-data="{ open: false, detailOpen: false }">
            <div class="flex flex-col gap-4 p-5 sm:flex-row">

                {{-- Preview — opens the content modal --}}
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

                {{-- Detail --}}
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
                        <span class="flex items-center gap-1.5">
                            <span class="avatar h-5 w-5 text-[10px]">{{ $content->creator?->initial() ?? '?' }}</span>
                            {{ $content->creator?->name ?? '-' }}
                        </span>
                        <span class="flex items-center gap-1">
                            <x-icon name="calendar" class="h-3 w-3"/>
                            {{ $content->scheduled_at?->translatedFormat('d M Y, H:i') ?? 'Belum dijadwal' }}
                        </span>
                        <span class="flex items-center gap-1">
                            <x-icon name="image" class="h-3 w-3"/> {{ $content->media->count() }} media
                        </span>
                    </div>
                </div>

                {{-- Actions — only for users who may actually decide --}}
                @can('decideApproval', $content)
                    <button @click="open = true" class="btn-primary btn-sm shrink-0 self-start">
                        <x-icon name="check-circle" class="h-3.5 w-3.5"/> Beri Keputusan
                    </button>
                @else
                    <span class="badge-slate shrink-0 self-start">Hanya lihat</span>
                @endcan
            </div>

            {{-- Decision modal --}}
            @can('decideApproval', $content)
                <x-decision-modal :title="'Keputusan: '.$content->title" :action="route('approvals.store', $content)">
                    <div>
                        <label class="label">Catatan <span class="text-slate-400">(wajib untuk revisi/tolak)</span></label>
                        <textarea name="note" rows="3" class="input"
                                  placeholder="Jelaskan alasan atau masukan Anda…">{{ old('note') }}</textarea>
                        @error('note') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    {{-- The curator owns the publish schedule --}}
                    <div class="mt-4">
                        <label class="label">Jadwal Terbit</label>
                        <input type="datetime-local" name="suggested_schedule_at"
                               value="{{ old('suggested_schedule_at') }}" class="input">
                        <p class="mt-1 text-xs text-slate-400">Ditetapkan saat menyetujui · zona waktu {{ config('app.timezone') }}.</p>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="label">Usulan caption (opsional)</label>
                            <textarea name="suggested_caption" rows="2" class="input"
                                      placeholder="Kosongkan bila caption sudah sesuai.">{{ old('suggested_caption') }}</textarea>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="label">Usulan hashtag (opsional)</label>
                            <input name="suggested_hashtags" value="{{ old('suggested_hashtags') }}" class="input" placeholder="promo diskon">
                        </div>
                    </div>

                    <x-slot:actions>
                        <button type="submit" name="action" value="approved" class="btn-success btn-sm">
                            <x-icon name="check" class="h-3.5 w-3.5"/> Setujui
                        </button>
                        <button type="submit" name="action" value="revision" class="btn-outline btn-sm"
                                @click="if (!$root.querySelector('[name=note]').value.trim()) { $event.preventDefault(); $root.querySelector('[name=note]').focus(); }">
                            <x-icon name="rotate" class="h-3.5 w-3.5"/> Minta Revisi
                        </button>
                        <button type="submit" name="action" value="rejected" class="btn-danger btn-sm"
                                @click="if (!$root.querySelector('[name=note]').value.trim()) { $event.preventDefault(); $root.querySelector('[name=note]').focus(); }">
                            <x-icon name="x" class="h-3.5 w-3.5"/> Tolak
                        </button>
                    </x-slot:actions>
                </x-decision-modal>
            @endcan

            {{-- Content preview modal (opened by clicking the title/thumbnail) --}}
            <x-content-preview-modal :content="$content"/>
        </div>
    @empty
        <div class="card">
            <x-empty-state icon="check-circle" title="Antrean kosong"
                           description="Tidak ada konten yang menunggu approval saat ini."/>
        </div>
    @endforelse

    @if ($contents->hasPages())
        <div class="mt-6">{{ $contents->links() }}</div>
    @endif

    <x-decision-history :decisions="$history"/>
</x-layouts.app>
