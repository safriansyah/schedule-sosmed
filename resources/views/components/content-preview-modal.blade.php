@props(['content'])

{{--
    Content preview modal for the approval / verification queues. Relies on an
    Alpine `detailOpen` boolean in the surrounding card's x-data. Opened by
    clicking the content title or thumbnail.
--}}
<div x-show="detailOpen" x-cloak class="fixed inset-0 z-[80] flex items-center justify-center p-4">
    <div x-show="detailOpen" x-transition.opacity @click="detailOpen = false"
         class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm"></div>

    <div x-show="detailOpen"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95 translate-y-2"
         x-transition:enter-end="opacity-100 scale-100 translate-y-0"
         class="card relative flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden p-0 shadow-2xl">

        {{-- Header --}}
        <div class="flex items-start justify-between gap-4 border-b border-slate-100 p-5 dark:border-white/5">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="truncate text-base font-semibold text-slate-800 dark:text-white">{{ $content->title }}</p>
                    <x-status-badge :status="$content->status"/>
                </div>
                <p class="mt-0.5 text-xs text-slate-400">
                    oleh {{ $content->creator?->name ?? '-' }}
                    · {{ $content->scheduled_at?->translatedFormat('d M Y, H:i') ?? 'Belum dijadwal' }}
                </p>
            </div>
            <button type="button" @click="detailOpen = false"
                    class="shrink-0 text-slate-400 transition hover:text-slate-600 dark:hover:text-slate-200">
                <x-icon name="x" class="h-5 w-5"/>
            </button>
        </div>

        {{-- Body --}}
        <div class="flex-1 space-y-5 overflow-y-auto p-5">

            {{-- Media gallery --}}
            @if ($content->media->isNotEmpty())
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach ($content->media as $media)
                        <div class="relative aspect-square overflow-hidden rounded-xl bg-slate-100 dark:bg-white/5">
                            @if ($media->isVideo())
                                <video class="h-full w-full object-cover" controls muted preload="metadata">
                                    <source src="{{ $media->url() }}#t=0.1">
                                </video>
                            @else
                                <img src="{{ $media->url() }}" alt="" class="h-full w-full object-cover">
                            @endif
                            <span class="absolute right-1.5 top-1.5 rounded-full bg-slate-900/70 px-1.5 py-0.5 text-[10px] font-semibold text-white">
                                {{ $media->type->label() }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="grid place-items-center rounded-xl bg-slate-50 py-10 text-slate-300 dark:bg-white/[0.02]">
                    <x-icon name="image" class="h-8 w-8"/>
                </div>
            @endif

            {{-- Caption --}}
            <div>
                <p class="label">Caption</p>
                {{-- break-words: caption sering memuat URL panjang tanpa spasi,
                     dan whitespace-pre-line saja tidak memutusnya. --}}
                <p class="whitespace-pre-line break-words text-sm text-slate-600 dark:text-slate-300">{{ $content->caption ?: '—' }}</p>
            </div>

            {{-- Hashtags --}}
            @if ($content->hashtags)
                <div>
                    <p class="label">Hashtag</p>
                    <p class="text-sm text-brand-600 dark:text-brand-300">{{ $content->hashtags }}</p>
                </div>
            @endif

            {{-- Meta grid --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @if ($content->mention)
                    <div>
                        <p class="label">Mention</p>
                        <p class="text-sm text-slate-600 dark:text-slate-300">{{ $content->mention }}</p>
                    </div>
                @endif
                @if ($content->location)
                    <div>
                        <p class="label">Lokasi</p>
                        <p class="text-sm text-slate-600 dark:text-slate-300">{{ $content->location }}</p>
                    </div>
                @endif
            </div>

            {{-- Internal note --}}
            @if ($content->internal_note)
                <div class="rounded-xl border border-slate-100 bg-slate-50/60 p-3 dark:border-white/5 dark:bg-white/[0.02]">
                    <p class="label !mb-1">Catatan Internal</p>
                    <p class="whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $content->internal_note }}</p>
                </div>
            @endif
        </div>

        {{-- Footer --}}
        <div class="flex items-center justify-end gap-2 border-t border-slate-100 p-4 dark:border-white/5">
            <a href="{{ route('contents.show', $content) }}" class="btn-outline btn-sm">
                <x-icon name="link" class="h-3.5 w-3.5"/> Halaman lengkap
            </a>
            <button type="button" @click="detailOpen = false" class="btn-primary btn-sm">Tutup</button>
        </div>
    </div>
</div>
