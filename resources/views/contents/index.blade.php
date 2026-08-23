<x-layouts.app title="Konten">
    <x-slot:header>
        <div>
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Konten</h1>
            <p class="mt-0.5 text-sm text-slate-400">{{ $contents->total() }} konten tercatat.</p>
        </div>

        @can('create', App\Models\Content::class)
            <a href="{{ route('contents.create') }}" class="btn-primary">
                <x-icon name="plus" class="h-4 w-4"/> Buat Konten
            </a>
        @endcan
    </x-slot:header>

    {{-- Status chips --}}
    <div class="mb-5 flex gap-2 overflow-x-auto pb-1">
        <a href="{{ route('contents.index', array_merge($filters, ['status' => null])) }}"
           @class(['badge-slate shrink-0 !px-3 !py-1.5', '!bg-brand-600 !text-white' => blank($filters['status'] ?? null)])>
            Semua <span class="opacity-70">{{ $counts->sum() }}</span>
        </a>

        @foreach (App\Enums\ContentStatus::cases() as $status)
            @continue($counts[$status->value] === 0)
            <a href="{{ route('contents.index', array_merge($filters, ['status' => $status->value])) }}"
               @class([$status->badge().' shrink-0 !px-3 !py-1.5', 'ring-2 ring-brand-500' => ($filters['status'] ?? null) === $status->value])>
                <x-icon :name="$status->icon()" class="h-3 w-3"/>
                {{ $status->label() }} <span class="opacity-70">{{ $counts[$status->value] }}</span>
            </a>
        @endforeach
    </div>

    {{-- Filters --}}
    <form method="GET" class="card mb-6 flex flex-col gap-3 p-4 sm:flex-row sm:items-end">
        @if (filled($filters['status'] ?? null))
            <input type="hidden" name="status" value="{{ $filters['status'] }}">
        @endif

        <div class="flex-1">
            <label for="q" class="label">Cari</label>
            <div class="relative">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"/>
                <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="input pl-9"
                       placeholder="Judul, caption, atau hashtag…">
            </div>
        </div>

        <div class="sm:w-48">
            <label for="creator" class="label">Creator</label>
            <select id="creator" name="creator" class="input">
                <option value="">Semua</option>
                @foreach ($creators as $creator)
                    <option value="{{ $creator->id }}" @selected(($filters['creator'] ?? null) == $creator->id)>
                        {{ $creator->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="flex gap-2">
            <button class="btn-primary"><x-icon name="filter" class="h-4 w-4"/> Filter</button>
            @if (array_filter($filters))
                <a href="{{ route('contents.index') }}" class="btn-outline"><x-icon name="x" class="h-4 w-4"/></a>
            @endif
        </div>
    </form>

    {{-- List --}}
    @if ($contents->isNotEmpty())
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
        @foreach ($contents as $content)
        @php $cover = $content->media->first(); @endphp

        <a href="{{ route('contents.show', $content) }}" class="card-glow group flex flex-col overflow-hidden">
            {{-- Media --}}
            <div class="relative aspect-video bg-slate-100 dark:bg-white/5">
                @if ($cover)
                    @if ($cover->isVideo())
                        <video class="h-full w-full object-cover" muted preload="metadata">
                            <source src="{{ $cover->url() }}#t=0.1">
                        </video>
                        <span class="absolute inset-0 grid place-items-center">
                            <span class="grid h-10 w-10 place-items-center rounded-full bg-slate-900/50 text-white backdrop-blur">
                                <x-icon name="video" class="h-5 w-5"/>
                            </span>
                        </span>
                    @else
                        <img src="{{ $cover->url() }}" alt="" class="h-full w-full object-cover transition group-hover:scale-[1.03]">
                    @endif
                @else
                    <div class="grid h-full place-items-center text-slate-300 dark:text-slate-600">
                        <x-icon name="image" class="h-8 w-8"/>
                    </div>
                @endif

                <div class="absolute left-2 top-2">
                    <x-status-badge :status="$content->status" class="shadow-sm"/>
                </div>

                @if ($content->media->count() > 1)
                    <span class="absolute right-2 top-2 rounded-full bg-slate-900/70 px-2 py-0.5 text-[10px] font-semibold text-white">
                        +{{ $content->media->count() - 1 }}
                    </span>
                @endif
            </div>

            {{-- Body --}}
            <div class="flex flex-1 flex-col p-3">
                <p class="line-clamp-1 text-sm font-semibold text-slate-800 dark:text-white">{{ $content->title }}</p>
                <p class="mt-1 line-clamp-2 flex-1 text-xs text-slate-400">{{ $content->caption ?: 'Tanpa caption' }}</p>

                <div class="mt-2.5 flex flex-col gap-1 border-t border-slate-100 pt-2.5 dark:border-white/5">
                    <span class="flex min-w-0 items-center gap-1.5 text-xs text-slate-400">
                        <span class="avatar h-5 w-5 shrink-0 text-[10px]">{{ $content->creator?->initial() ?? '?' }}</span>
                        <span class="truncate">{{ $content->creator?->name ?? '-' }}</span>
                    </span>

                    <span class="flex items-center gap-1 text-xs text-slate-400">
                        <x-icon name="clock" class="h-3 w-3 shrink-0"/>
                        {{ $content->scheduled_at?->translatedFormat('d M, H:i') ?? 'Belum dijadwal' }}
                    </span>
                </div>
            </div>
        </a>

        @endforeach
    </div>
    @else
        <div class="card">
            <x-empty-state
                icon="image"
                title="Belum ada konten"
                :description="array_filter($filters) ? 'Tidak ada hasil untuk filter ini.' : 'Mulai dengan membuat draft konten pertama Anda.'">
                <x-slot:action>
                    @can('create', App\Models\Content::class)
                        <a href="{{ route('contents.create') }}" class="btn-primary">
                            <x-icon name="plus" class="h-4 w-4"/> Buat Konten
                        </a>
                    @endcan
                </x-slot:action>
            </x-empty-state>
        </div>
    @endif

    @if ($contents->hasPages())
        <div class="mt-6">{{ $contents->links() }}</div>
    @endif
</x-layouts.app>
