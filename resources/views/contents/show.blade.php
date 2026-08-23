<x-layouts.app :title="$content->title">
    <x-slot:header>
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <x-status-badge :status="$content->status"/>
                @if ($content->is_carousel)
                    <span class="badge-slate">Carousel</span>
                @endif
            </div>
            <h1 class="mt-1 truncate text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">
                {{ $content->title }}
            </h1>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('contents.index') }}" class="btn-outline">
                <x-icon name="chevron-left" class="h-4 w-4"/> Kembali
            </a>

            @can('update', $content)
                <a href="{{ route('contents.edit', $content) }}" class="btn-outline">
                    <x-icon name="edit" class="h-4 w-4"/> Ubah
                </a>
            @endcan

            @can('submit', $content)
                <form method="POST" action="{{ route('contents.submit', $content) }}">
                    @csrf
                    <button class="btn-primary">
                        <x-icon name="send" class="h-4 w-4"/> Kirim ke Approval
                    </button>
                </form>
            @endcan

            @can('retry', $content)
                <form method="POST" action="{{ route('contents.retry', $content) }}">
                    @csrf
                    <button class="btn-primary">
                        <x-icon name="refresh" class="h-4 w-4"/> Coba Terbitkan Lagi
                    </button>
                </form>
            @endcan

            @can('cancel', $content)
                <form method="POST" action="{{ route('contents.cancel', $content) }}"
                      onsubmit="return confirm('Batalkan konten ini? Statusnya menjadi Dibatalkan.')">
                    @csrf
                    <button class="btn-outline">
                        <x-icon name="x" class="h-4 w-4"/> Batalkan
                    </button>
                </form>
            @endcan

            @can('delete', $content)
                <form method="POST" action="{{ route('contents.destroy', $content) }}"
                      onsubmit="return confirm('Hapus konten ini?')">
                    @csrf
                    @method('DELETE')
                    <button class="btn-danger"><x-icon name="trash" class="h-4 w-4"/></button>
                </form>
            @endcan
        </div>
    </x-slot:header>

    @if ($content->last_error)
        <div class="card mb-6 border-rose-200 bg-rose-50/60 p-4 dark:border-rose-500/20 dark:bg-rose-500/5">
            <p class="flex items-start gap-2 text-sm text-rose-600 dark:text-rose-400">
                <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0"/>
                <span>{{ $content->last_error }}</span>
            </p>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

        {{-- Left: media + caption --}}
        <div class="space-y-6 lg:col-span-2">

            {{-- Media --}}
            <div class="card p-5">
                <p class="mb-4 text-sm font-semibold text-slate-700 dark:text-slate-200">
                    Media <span class="text-slate-400">({{ $content->media->count() }})</span>
                </p>

                @if ($content->media->isEmpty())
                    <x-empty-state icon="image" title="Belum ada media" class="!py-8"/>
                @else
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach ($content->media as $media)
                            <div class="overflow-hidden rounded-xl border border-slate-200 dark:border-white/10">
                                @if ($media->isVideo())
                                    <video class="aspect-square w-full object-cover" controls preload="metadata">
                                        <source src="{{ $media->url() }}">
                                    </video>
                                @else
                                    <img src="{{ $media->url() }}" alt="" class="aspect-square w-full object-cover">
                                @endif
                                <p class="flex items-center justify-between px-2 py-1.5 text-[11px] text-slate-400">
                                    <span>{{ $media->width }}×{{ $media->height }}</span>
                                    <span>{{ $media->humanSize() }}</span>
                                </p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Caption preview --}}
            <div class="card p-5">
                <p class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-200">Pratinjau Caption</p>
                <div class="rounded-xl bg-slate-50 p-4 dark:bg-white/[0.03]">
                    <p class="whitespace-pre-line text-sm text-slate-700 dark:text-slate-300">{{ $content->composedCaption() ?: 'Tanpa caption' }}</p>
                </div>

                <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @if ($content->mention)
                        <div><dt class="text-xs text-slate-400">Mention</dt><dd class="text-sm text-slate-700 dark:text-slate-300">{{ $content->mention }}</dd></div>
                    @endif
                    @if ($content->location)
                        <div><dt class="text-xs text-slate-400">Lokasi</dt><dd class="text-sm text-slate-700 dark:text-slate-300">{{ $content->location }}</dd></div>
                    @endif
                </dl>

                @if ($content->internal_note)
                    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50/60 p-3 dark:border-amber-500/20 dark:bg-amber-500/5">
                        <p class="text-xs font-semibold text-amber-700 dark:text-amber-400">Catatan internal</p>
                        <p class="mt-1 text-sm text-amber-800 dark:text-amber-300/90">{{ $content->internal_note }}</p>
                    </div>
                @endif
            </div>

            {{-- Workflow timeline --}}
            <div class="card p-5">
                <p class="mb-4 text-sm font-semibold text-slate-700 dark:text-slate-200">Riwayat Workflow</p>

                @php
                    $timeline = collect()
                        ->concat($content->approvals->map(fn ($a) => [
                            'at' => $a->created_at, 'icon' => $a->action->icon(), 'badge' => $a->action->badge(),
                            'title' => 'Approval — '.$a->action->label(), 'by' => $a->curator?->name, 'note' => $a->note,
                        ]))
                        ->concat($content->verifications->map(fn ($v) => [
                            'at' => $v->created_at, 'icon' => $v->action->icon(), 'badge' => $v->action->badge(),
                            'title' => 'Verifikasi — '.$v->action->label(), 'by' => $v->verifier?->name, 'note' => $v->note,
                        ]))
                        ->concat($content->revisions->map(fn ($r) => [
                            'at' => $r->created_at, 'icon' => 'rotate', 'badge' => 'badge-pink',
                            'title' => 'Permintaan revisi', 'by' => $r->requester?->name, 'note' => $r->note,
                        ]))
                        ->sortByDesc('at');
                @endphp

                @forelse ($timeline as $item)
                    <div class="flex gap-3 border-l-2 border-slate-100 pb-5 pl-4 last:pb-0 dark:border-white/5">
                        <div class="-ml-[27px] mt-0.5">
                            <span class="{{ $item['badge'] }} !h-7 !w-7 !justify-center !rounded-full !p-0">
                                <x-icon :name="$item['icon']" class="h-3.5 w-3.5"/>
                            </span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $item['title'] }}</p>
                            <p class="text-xs text-slate-400">
                                {{ $item['by'] ?? 'Sistem' }} · {{ $item['at']?->translatedFormat('d M Y, H:i') }}
                            </p>
                            @if ($item['note'])
                                <p class="mt-1.5 rounded-lg bg-slate-50 p-2 text-sm text-slate-600 dark:bg-white/[0.03] dark:text-slate-300">
                                    {{ $item['note'] }}
                                </p>
                            @endif
                        </div>
                    </div>
                @empty
                    <x-empty-state icon="activity" title="Belum ada aktivitas workflow"
                                   description="Riwayat approval dan verifikasi akan tampil di sini." class="!py-8"/>
                @endforelse
            </div>
        </div>

        {{-- Right: meta --}}
        <div class="space-y-6">

            <div class="card p-5">
                <p class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-200">Jadwal</p>
                <p class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                    <x-icon name="calendar" class="h-4 w-4 text-slate-400"/>
                    {{ $content->scheduled_at?->translatedFormat('l, d F Y — H:i') ?? 'Belum dijadwalkan' }}
                </p>
                @if ($content->published_at)
                    <p class="mt-2 flex items-center gap-2 text-sm text-emerald-600 dark:text-emerald-400">
                        <x-icon name="check-circle" class="h-4 w-4"/>
                        Terbit {{ $content->published_at->translatedFormat('d M Y, H:i') }}
                    </p>
                @endif
            </div>

            <div class="card p-5">
                <p class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-200">Akun Tujuan</p>
                @forelse ($content->schedules as $schedule)
                    <div class="row-hover -mx-2 flex items-center gap-3 rounded-xl px-2 py-2">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-white"
                              style="background: {{ $schedule->account->platform->color() }}">
                            <x-icon :name="$schedule->account->platform->icon()" class="h-4 w-4"/>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-slate-700 dark:text-slate-200">{{ $schedule->account->name }}</span>
                            <span class="block truncate text-xs text-slate-400">{{ $schedule->account->handle() }}</span>
                        </span>
                        @if ($schedule->permalink)
                            <a href="{{ $schedule->permalink }}" target="_blank" rel="noopener"
                               class="text-slate-400 transition hover:text-brand-600" title="Lihat postingan">
                                <x-icon name="link" class="h-4 w-4"/>
                            </a>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-slate-400">Belum ada akun tujuan.</p>
                @endforelse
            </div>

            <div class="card p-5">
                <p class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-200">Tim</p>
                <dl class="space-y-3">
                    @foreach ([
                        ['Creator', $content->creator, 'image'],
                        ['Curator', $content->curator, 'check-circle'],
                        ['Verifikator', $content->verifier, 'badge-check'],
                    ] as [$role, $person, $icon])
                        <div class="flex items-center gap-3">
                            <x-icon :name="$icon" class="h-4 w-4 shrink-0 text-slate-400"/>
                            <dt class="w-24 shrink-0 text-xs text-slate-400">{{ $role }}</dt>
                            <dd class="min-w-0 flex-1 truncate text-sm text-slate-700 dark:text-slate-300">
                                {{ $person?->name ?? 'Belum ada' }}
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>
    </div>
</x-layouts.app>
