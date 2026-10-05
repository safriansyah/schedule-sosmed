{{-- One account's whole history, newest first. Follow up and Close happen
     on each interaction's own page, where the conversation is shown. --}}
@forelse ($interactions as $interaction)
    <div class="relative">
        <x-interaction-row :interaction="$interaction"/>

        <div class="flex flex-wrap items-center gap-2 px-4 pb-3 pl-16 text-[11px] text-slate-400">
            <span class="{{ $interaction->status->badge() }}">
                <x-icon :name="$interaction->status->icon()" class="h-3 w-3"/> {{ $interaction->status->label() }}
            </span>
            @if ($interaction->follow_ups_count > 0)
                <span class="badge-violet"><x-icon name="reply" class="h-3 w-3"/> {{ $interaction->follow_ups_count }} follow up</span>
            @endif
            <a href="{{ route('interactions.show', $interaction) }}#follow-up" class="font-semibold text-brand-600 hover:underline dark:text-brand-300">
                Follow up / Close →
            </a>
        </div>
    </div>
@empty
    <p class="p-4 text-sm text-slate-400">Tidak ada interaksi.</p>
@endforelse

@if ($interactions->count() >= 100)
    <p class="p-3 text-center text-xs text-slate-400">Menampilkan 100 interaksi terbaru dari akun ini.</p>
@endif
