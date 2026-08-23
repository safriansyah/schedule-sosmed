@props(['comment', 'showPost' => false])

{{-- One comment: avatar (with graceful fallback), who, when, text, likes. --}}
<div class="row-hover -mx-2 flex items-start gap-3 rounded-xl px-2 py-2.5">
    <span class="avatar relative h-8 w-8 shrink-0 overflow-hidden text-xs">
        {{ $comment->initial() }}
        @if ($comment->avatar_url)
            {{-- Instagram CDN avatars are signed and expire; if the link is dead
                 the image removes itself and the initial shows through. --}}
            <img src="{{ $comment->avatar_url }}" alt="" loading="lazy" referrerpolicy="no-referrer"
                 class="absolute inset-0 h-full w-full object-cover" onerror="this.remove()">
        @endif
    </span>

    <div class="min-w-0 flex-1">
        <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
            @if ($comment->username)
                <a href="{{ route('monitoring.profile', ['username' => ltrim($comment->username, '@')]) }}"
                   class="text-sm font-semibold text-slate-700 transition hover:text-brand-600 dark:text-slate-200 dark:hover:text-brand-400">{{ $comment->handle() }}</a>
            @else
                <span class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $comment->handle() }}</span>
            @endif
            @if ($comment->is_verified)
                <x-icon name="badge-check" class="h-3.5 w-3.5 text-sky-500"/>
            @endif
            @if ($comment->full_name)
                <span class="truncate text-xs text-slate-400">{{ $comment->full_name }}</span>
            @endif
            @if ($comment->commented_at)
                <span class="text-xs text-slate-400">· {{ $comment->commented_at->diffForHumans() }}</span>
            @endif
        </p>

        <p class="mt-0.5 whitespace-pre-line break-words text-sm text-slate-600 dark:text-slate-300">{{ $comment->text }}</p>

        @if ($showPost && $comment->media)
            <a href="{{ route('monitoring.show', $comment->media) }}"
               class="mt-1.5 inline-flex max-w-full items-center gap-1.5 text-xs text-slate-400 transition hover:text-brand-600">
                <x-icon name="image" class="h-3 w-3 shrink-0"/>
                <span class="truncate">{{ $comment->media->shortCaption(48) }}</span>
            </a>
        @endif
    </div>

    @if ($comment->like_count > 0)
        <span class="badge-slate shrink-0">
            <x-icon name="heart" class="h-3 w-3"/> {{ number_format($comment->like_count) }}
        </span>
    @endif
</div>
