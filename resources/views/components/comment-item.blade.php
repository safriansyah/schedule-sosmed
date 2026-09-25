@props(['comment', 'showPost' => false])

{{--
    One comment inside the Monitoring screens.

    `$comment` is an Interaction. The inbox has its own richer row
    (<x-interaction-row>); this stays the lightweight read-only rendering used
    where the focus is the post's performance rather than handling the message.
--}}
@php
    $sentiment = $comment->effectiveSentiment();
    $contact   = $comment->contact;
@endphp

<div class="row-hover -mx-2 flex items-start gap-3 rounded-xl px-2 py-2.5">
    <span class="avatar relative h-8 w-8 shrink-0 overflow-hidden text-xs">
        {{ $comment->initial() }}
        @if ($comment->avatar())
            {{-- Instagram CDN avatars are signed and expire; if the link is dead
                 the image removes itself and the initial shows through. --}}
            <img src="{{ $comment->avatar() }}" alt="" loading="lazy" referrerpolicy="no-referrer"
                 class="absolute inset-0 h-full w-full object-cover" onerror="this.remove()">
        @endif
    </span>

    <div class="min-w-0 flex-1">
        <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
            @if ($comment->author_handle)
                <a href="{{ route('monitoring.profile', ['username' => ltrim($comment->author_handle, '@')]) }}"
                   class="max-w-[14rem] truncate text-sm font-semibold text-slate-700 transition hover:text-brand-600 dark:text-slate-200 dark:hover:text-brand-400">{{ $comment->handle() }}</a>
            @else
                <span class="max-w-[14rem] truncate text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $comment->handle() }}</span>
            @endif

            @if ($comment->author_verified)
                <x-icon name="badge-check" class="h-3.5 w-3.5 text-sky-500"/>
            @endif

            @if ($contact?->isAgent())
                <span class="badge-green">Agent</span>
            @endif

            @if ($comment->author_name)
                <span class="truncate text-xs text-slate-400">{{ $comment->author_name }}</span>
            @endif

            @if ($comment->occurred_at)
                <span class="text-xs text-slate-400">· {{ $comment->occurred_at->diffForHumans() }}</span>
            @endif
        </p>

        <p class="mt-0.5 whitespace-pre-line break-words text-sm text-slate-600 dark:text-slate-300">{{ $comment->text }}</p>

        {{-- Classification, if it has run. Compact here — the full breakdown
             lives on the interaction detail page. --}}
        @if ($comment->is_urgent || $sentiment || $comment->intent)
            <p class="mt-1.5 flex flex-wrap items-center gap-1.5">
                @if ($comment->is_urgent)
                    <span class="badge-red"><x-icon name="flame" class="h-3 w-3"/> Mendesak</span>
                @endif
                @if ($sentiment)
                    <span class="{{ $sentiment->badge() }}">{{ $sentiment->label() }}</span>
                @endif
                @if ($comment->intent)
                    <span class="{{ $comment->intent->badge() }}">{{ $comment->intent->label() }}</span>
                @endif
                <a href="{{ route('interactions.show', $comment) }}"
                   class="text-[11px] font-semibold text-brand-600 hover:underline dark:text-brand-400">Tangani →</a>
            </p>
        @endif

        @if ($showPost && $comment->source)
            <a href="{{ route('monitoring.show', $comment->source) }}"
               class="mt-1.5 inline-flex max-w-full items-center gap-1.5 text-xs text-slate-400 transition hover:text-brand-600">
                <x-icon name="image" class="h-3 w-3 shrink-0"/>
                <span class="truncate">{{ $comment->source->shortCaption(48) }}</span>
            </a>
        @endif
    </div>

    @if ($comment->like_count > 0)
        <span class="badge-slate shrink-0">
            <x-icon name="heart" class="h-3 w-3"/> {{ number_format($comment->like_count) }}
        </span>
    @endif
</div>
