@props(['decisions'])

{{-- Recently processed decisions by the current curator/verifier. --}}
@if ($decisions->isNotEmpty())
    <div class="mt-10">
        <h2 class="mb-3 text-base font-bold text-slate-800 dark:text-white">
            Riwayat keputusan saya
            <span class="text-sm font-normal text-slate-400">({{ $decisions->total() }})</span>
        </h2>

        <div class="card divide-y divide-slate-100 dark:divide-white/5">
            @foreach ($decisions as $decision)
                <div class="flex items-start gap-3 p-4">
                    <span @class([
                        'mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-lg',
                        'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10' => $decision->action->value === 'approved',
                        'bg-rose-50 text-rose-600 dark:bg-rose-500/10' => $decision->action->value === 'rejected',
                        'bg-pink-50 text-pink-600 dark:bg-pink-500/10' => $decision->action->value === 'revision',
                    ])>
                        <x-icon :name="$decision->action->icon()" class="h-4 w-4"/>
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2">
                            @if ($decision->content)
                                <a href="{{ route('contents.show', $decision->content) }}"
                                   class="truncate text-sm font-semibold text-slate-700 transition hover:text-brand-600 dark:text-slate-200">
                                    {{ $decision->content->title }}
                                </a>
                            @else
                                <span class="text-sm font-semibold text-slate-400 line-through">Konten dihapus</span>
                            @endif
                            <span class="{{ $decision->action->badge() }}">{{ $decision->action->label() }}</span>
                        </p>

                        @if ($decision->note)
                            <p class="mt-1 line-clamp-2 text-sm text-slate-500 dark:text-slate-400">{{ $decision->note }}</p>
                        @endif

                        <p class="mt-1 text-xs text-slate-400">
                            {{ $decision->content?->creator?->name ?? 'Tanpa pembuat' }}
                            · {{ $decision->created_at->translatedFormat('d M Y, H:i') }}
                            ({{ $decision->created_at->diffForHumans() }})
                        </p>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($decisions->hasPages())
            <div class="mt-4">{{ $decisions->links() }}</div>
        @endif
    </div>
@endif
