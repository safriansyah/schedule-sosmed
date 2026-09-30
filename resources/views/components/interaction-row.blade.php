@props(['interaction', 'selectable' => false, 'actions' => true])

@php
    /**
     * One row in the inbox.
     *
     * The reading order is deliberate: who → what they said → what the AI made
     * of it → how long it has been waiting. Staff scan the left edge for the
     * person and the right edge for whether it is late.
     *
     * The checkbox sits OUTSIDE the anchor: a control nested in a link is
     * unclickable in practice, since the link swallows the event.
     */
    $sentiment = $interaction->effectiveSentiment();
    $contact   = $interaction->contact;
    $late      = $interaction->isBreachingSla();

    // relationLoaded() first: without it, a list of 25 rows would fire 25
    // queries looking for a ticket that mostly does not exist. Callers that
    // want the button eager-load `ticket`; the rest get no button rather than
    // an N+1.
    $ticket = $interaction->relationLoaded('ticket') ? $interaction->ticket : null;
    $actions = $actions && $interaction->relationLoaded('ticket');
@endphp

<div class="row-hover relative flex items-start gap-3 px-4 py-3.5 transition
            {{ $interaction->is_urgent ? 'bg-rose-50/50 dark:bg-rose-500/[0.04]' : '' }}">

    {{-- Urgent items get a bar on the leading edge: visible while scrolling
         fast, and it does not depend on colour alone thanks to the flame icon. --}}
    @if ($interaction->is_urgent)
        <span class="absolute inset-y-0 left-0 w-1 bg-rose-500"></span>
    @endif

    @if ($selectable)
        <input type="checkbox" name="ids[]" value="{{ $interaction->id }}"
               x-model="selected"
               aria-label="Pilih interaksi dari {{ $interaction->handle() }}"
               class="mt-3 h-4 w-4 shrink-0 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-white/20 dark:bg-ink-850">
    @endif

    <a href="{{ route('interactions.show', $interaction) }}" class="flex min-w-0 flex-1 items-start gap-3">
        {{-- Avatar --}}
        <span class="avatar relative mt-0.5 h-9 w-9 shrink-0 overflow-hidden text-xs">
            {{ $interaction->initial() }}
            @if ($interaction->avatar())
                {{-- Platform CDN avatars are signed and expire; a dead link removes
                     itself so the initial shows through. --}}
                <img src="{{ $interaction->avatar() }}" alt="" loading="lazy" referrerpolicy="no-referrer"
                     class="absolute inset-0 h-full w-full object-cover" onerror="this.remove()">
            @endif
        </span>

        <div class="min-w-0 flex-1">
            {{-- Who --}}
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span class="max-w-[14rem] truncate text-sm font-semibold text-slate-700 dark:text-slate-200">
                    {{ $interaction->handle() }}
                </span>

                @if ($interaction->author_verified)
                    <x-icon name="badge-check" class="h-3.5 w-3.5 text-sky-500"/>
                @endif

                @if ($contact?->isAgent())
                    <span class="badge-green"><x-icon name="badge-check" class="h-3 w-3"/> Agent</span>
                @elseif ($contact && $contact->status === \App\Enums\ContactStatus::Candidate)
                    <span class="badge-amber">Calon Agent</span>
                @endif

                <span class="badge-slate">
                    <x-icon :name="$interaction->channel->icon()" class="h-3 w-3"/>
                    {{ $interaction->type->label() }}
                </span>

                @if ($interaction->occurred_at)
                    <span class="text-xs text-slate-400">· {{ $interaction->occurred_at->diffForHumans() }}</span>
                @endif
            </div>

            {{-- What they said --}}
            <p class="mt-1 line-clamp-2 break-words text-sm text-slate-600 dark:text-slate-300">
                {{ $interaction->text ?: '—' }}
            </p>

            {{-- What the AI made of it --}}
            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                @if ($interaction->is_urgent)
                    <span class="badge-red"><x-icon name="flame" class="h-3 w-3"/> Mendesak</span>
                @endif

                @if ($sentiment)
                    <span class="{{ $sentiment->badge() }}">
                        <x-icon :name="$sentiment->icon()" class="h-3 w-3"/> {{ $sentiment->label() }}
                    </span>
                @endif

                @if ($interaction->intent)
                    <span class="{{ $interaction->intent->badge() }}">
                        <x-icon :name="$interaction->intent->icon()" class="h-3 w-3"/> {{ $interaction->intent->label() }}
                    </span>
                @endif

                @if ($interaction->lead_potential >= 50)
                    <span class="badge-violet"><x-icon name="target" class="h-3 w-3"/> Potensi {{ $interaction->lead_potential }}%</span>
                @endif

                @if ($interaction->wasOverridden())
                    <span class="badge-slate" title="Sentimen dikoreksi manual">
                        <x-icon name="edit" class="h-3 w-3"/> Dikoreksi
                    </span>
                @elseif ($interaction->ai_classified_at === null)
                    <span class="badge-slate"><x-icon name="clock" class="h-3 w-3"/> Belum dinilai</span>
                @endif
            </div>
        </div>

        {{-- Status & waiting time --}}
        <div class="flex shrink-0 flex-col items-end gap-1.5">
            <span class="{{ $interaction->status->badge() }}">{{ $interaction->status->label() }}</span>

            {{-- Who is holding this. Previously it was hidden the moment a row
                 went late, which is exactly when a supervisor most needs to
                 know whose it is — so lateness and owner now both show. --}}
            @if ($interaction->assignee)
                <span class="badge-slate max-w-[10rem] truncate" title="Ditugaskan ke {{ $interaction->assignee->name }}">
                    <x-icon name="user" class="h-3 w-3"/> {{ $interaction->assignee->name }}
                </span>
            @endif

            @if ($late)
                <span class="text-[11px] font-semibold text-rose-500">
                    Telat {{ round($interaction->waitingHours() - $interaction->slaHours()) }} jam
                </span>
            @endif
        </div>
    </a>

    {{-- "Add to Ticket". Outside the anchor above, because a form nested in a
         link never submits — the link swallows the click.

         And NOT a <form> of its own either. This row is rendered inside the
         inbox's bulk form, and HTML forbids nested forms: the parser drops the
         inner start tag, so the button silently became part of the bulk form
         and posted to interactions/bulk with no action — which answered a
         click on "Add Ticket" with "Pilih tindakan yang ingin dijalankan."

         formaction/formmethod retarget the surrounding form for this one
         button instead, which is what that attribute pair is for. The bulk
         form already carries the CSRF token, and fromInteraction() reads the
         interaction from the route, so the ids[] that travel along are simply
         ignored. --}}
    @if ($actions)
        <div class="flex shrink-0 flex-col items-end gap-1.5">
            @if ($ticket)
                <a href="{{ route('tickets.show', $ticket) }}" class="btn-outline btn-sm" title="Buka tiket">
                    <x-icon name="file-text" class="h-3.5 w-3.5"/>
                    <span class="font-mono">{{ $ticket->number }}</span>
                </a>
            @elseif (auth()->user()?->hasPermission(\App\Enums\Permission::CreateTickets))
                <button type="submit" class="btn-outline btn-sm" title="Jadikan tiket"
                        formmethod="POST"
                        formaction="{{ route('tickets.fromInteraction', $interaction) }}">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Add Ticket
                </button>
            @endif
        </div>
    @endif
</div>
