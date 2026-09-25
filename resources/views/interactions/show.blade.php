@php
    $sentiment = $interaction->effectiveSentiment();
    $canHandle = auth()->user()->can(\App\Enums\Permission::HandleInteractions->value);
    $contact   = $interaction->contact;
@endphp

<x-layouts.app title="Detail Interaksi">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">
                Interaksi dari {{ $interaction->handle() }}
            </h1>
            <p class="mt-0.5 text-sm text-slate-400">
                {{ $interaction->channel->label() }} · {{ $interaction->type->label() }}
                @if ($interaction->occurred_at)
                    · {{ $interaction->occurred_at->translatedFormat('d M Y, H:i') }}
                @endif
            </p>
        </div>

        <a href="{{ url()->previous(route('interactions.index')) }}" class="btn-outline">
            <x-icon name="chevron-left" class="h-4 w-4"/> Kembali
        </a>
    </x-slot:header>

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ============================ Left: the message ==================== --}}
        <div class="space-y-6 lg:col-span-2">

            {{-- The message itself --}}
            <div class="card p-5 {{ $interaction->is_urgent ? 'border-rose-500/40 dark:border-rose-500/30' : '' }}">
                @if ($interaction->is_urgent)
                    <div class="-mx-5 -mt-5 mb-5 flex items-center gap-2 rounded-t-2xl bg-rose-500/10 px-5 py-3 text-sm font-semibold text-rose-600 dark:text-rose-400">
                        <x-icon name="flame" class="h-4 w-4"/>
                        Ditandai mendesak — perlu ditangani dalam {{ config('crm.sla.urgent_hours') }} jam
                    </div>
                @endif

                <div class="flex items-start gap-3">
                    <span class="avatar relative h-11 w-11 shrink-0 overflow-hidden text-sm">
                        {{ $interaction->initial() }}
                        @if ($interaction->avatar())
                            <img src="{{ $interaction->avatar() }}" alt="" loading="lazy" referrerpolicy="no-referrer"
                                 class="absolute inset-0 h-full w-full object-cover" onerror="this.remove()">
                        @endif
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-x-2">
                            <span class="max-w-full truncate font-bold text-slate-800 dark:text-white">{{ $interaction->handle() }}</span>
                            @if ($interaction->author_verified)
                                <x-icon name="badge-check" class="h-4 w-4 text-sky-500"/>
                            @endif
                            @if ($interaction->author_name)
                                <span class="max-w-full truncate text-sm text-slate-400">{{ $interaction->author_name }}</span>
                            @endif
                        </p>

                        <p class="mt-3 whitespace-pre-line break-words text-[15px] leading-relaxed text-slate-700 dark:text-slate-200">
                            {{ $interaction->text ?: '(tanpa teks)' }}
                        </p>

                        @if ($interaction->like_count > 0 || $interaction->reply_count > 0)
                            <div class="mt-3 flex gap-3 text-xs text-slate-400">
                                @if ($interaction->like_count > 0)
                                    <span class="inline-flex items-center gap-1">
                                        <x-icon name="heart" class="h-3 w-3"/> {{ number_format($interaction->like_count) }}
                                    </span>
                                @endif
                                @if ($interaction->reply_count > 0)
                                    <span class="inline-flex items-center gap-1">
                                        <x-icon name="message" class="h-3 w-3"/> {{ number_format($interaction->reply_count) }} balasan
                                    </span>
                                @endif
                            </div>
                        @endif

                        @if ($interaction->source)
                            <a href="{{ route('monitoring.show', $interaction->source) }}"
                               class="mt-4 inline-flex max-w-full items-center gap-1.5 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500 transition hover:text-brand-600 dark:bg-white/5 dark:text-slate-400">
                                <x-icon name="image" class="h-3.5 w-3.5 shrink-0"/>
                                <span class="truncate">Pada postingan: {{ $interaction->source->shortCaption(60) }}</span>
                            </a>
                        @endif
                    </div>
                </div>
            </div>

            {{-- What the classifier decided, and how to disagree with it --}}
            <div class="card p-5">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-sm font-bold text-slate-800 dark:text-white">
                        <x-icon name="robot" class="h-4 w-4 text-brand-500"/> Hasil Klasifikasi
                    </h2>
                    @if ($interaction->ai_model)
                        <span class="badge-slate">{{ $interaction->ai_model }} · {{ $interaction->ai_confidence }}%</span>
                    @endif
                </div>

                @if ($interaction->ai_classified_at === null)
                    <p class="text-sm text-slate-400">Belum dinilai. Jalankan “Nilai sekarang” dari halaman inbox.</p>
                @else
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="rounded-xl bg-slate-50 p-3 dark:bg-white/5">
                            <p class="text-xs text-slate-400">Sentimen</p>
                            <p class="mt-1">
                                <span class="{{ $sentiment?->badge() ?? 'badge-slate' }}">
                                    @if ($sentiment)<x-icon :name="$sentiment->icon()" class="h-3 w-3"/>@endif
                                    {{ $sentiment?->label() ?? '—' }}
                                </span>
                                @if ($interaction->wasOverridden())
                                    <span class="ml-1 text-[11px] text-slate-400">
                                        (AI: {{ $interaction->sentiment?->label() }})
                                    </span>
                                @endif
                            </p>
                        </div>

                        <div class="rounded-xl bg-slate-50 p-3 dark:bg-white/5">
                            <p class="text-xs text-slate-400">Jenis</p>
                            <p class="mt-1">
                                <span class="{{ $interaction->intent?->badge() ?? 'badge-slate' }}">
                                    {{ $interaction->intent?->label() ?? '—' }}
                                </span>
                            </p>
                        </div>

                        <div class="rounded-xl bg-slate-50 p-3 dark:bg-white/5">
                            <p class="text-xs text-slate-400">Potensi jadi mahasiswa</p>
                            <div class="mt-2 flex items-center gap-2">
                                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-200 dark:bg-white/10">
                                    <div class="h-full rounded-full bg-violet-500" style="width: {{ $interaction->lead_potential }}%"></div>
                                </div>
                                <span class="text-xs font-semibold text-slate-600 dark:text-slate-300">{{ $interaction->lead_potential }}%</span>
                            </div>
                        </div>

                        <div class="rounded-xl bg-slate-50 p-3 dark:bg-white/5">
                            <p class="text-xs text-slate-400">Perlu dibalas</p>
                            <p class="mt-1 text-sm font-semibold text-slate-700 dark:text-slate-200">
                                {{ $interaction->needs_reply ? 'Ya' : 'Tidak' }}
                            </p>
                        </div>
                    </div>

                    @if ($interaction->ai_raw['reason'] ?? null)
                        <p class="mt-3 rounded-xl bg-slate-50 p-3 text-xs italic text-slate-500 dark:bg-white/5 dark:text-slate-400">
                            “{{ $interaction->ai_raw['reason'] }}”
                        </p>
                    @endif

                    @if ($interaction->ai_confidence < config('crm.ai.low_confidence_below'))
                        <p class="mt-3 flex items-start gap-2 rounded-xl bg-amber-50 p-3 text-xs text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">
                            <x-icon name="alert" class="mt-px h-3.5 w-3.5 shrink-0"/>
                            Keyakinan rendah — sebaiknya diperiksa manusia.
                        </p>
                    @endif
                @endif

                @if ($canHandle)
                    {{-- Correction. The AI's answer is kept; this only adds the
                         human verdict beside it. --}}
                    <form method="POST" action="{{ route('interactions.override', $interaction) }}"
                          class="mt-4 flex flex-wrap items-end gap-2 border-t border-slate-100 pt-4 dark:border-white/5">
                        @csrf
                        <div class="w-40">
                            <label for="sentiment" class="label">Koreksi sentimen</label>
                            <select id="sentiment" name="sentiment" class="input">
                                @foreach ($sentiments as $value => $label)
                                    <option value="{{ $value }}" @selected($sentiment?->value === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <label class="flex items-center gap-2 pb-2.5 text-sm text-slate-600 dark:text-slate-300">
                            <input type="hidden" name="is_urgent" value="0">
                            <input type="checkbox" name="is_urgent" value="1" @checked($interaction->is_urgent)
                                   class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-white/20 dark:bg-ink-850">
                            Mendesak
                        </label>

                        <button class="btn-outline"><x-icon name="check" class="h-4 w-4"/> Simpan koreksi</button>
                    </form>
                @endif
            </div>

            {{-- Escalation into Ticketing. An interaction that needs real
                 follow-up becomes a ticket; the comment stays here either way. --}}
            <div class="card p-5">
                <h2 class="mb-3 flex items-center gap-2 text-sm font-bold text-slate-800 dark:text-white">
                    <x-icon name="file-text" class="h-4 w-4 text-brand-500"/>
                    Tiket
                </h2>

                @if ($interaction->ticket)
                    <a href="{{ route('tickets.show', $interaction->ticket) }}"
                       class="flex items-center gap-3 rounded-xl border border-slate-200 p-3 transition hover:bg-slate-50 dark:border-white/10 dark:hover:bg-white/[0.02]">
                        <span class="font-mono text-xs font-bold text-brand-600 dark:text-brand-400">
                            {{ $interaction->ticket->number }}
                        </span>
                        <span class="{{ $interaction->ticket->status->badge() }}">
                            {{ $interaction->ticket->status->label() }}
                        </span>
                        <span class="ml-auto text-xs text-slate-400">Buka tiket →</span>
                    </a>
                @else
                    <p class="mb-3 text-xs text-slate-400">
                        Belum ada tiket. Membuat tiket menyalin username, isi komentar, dan tautan postingan
                        sebagai referensi sumber.
                    </p>

                    @can(\App\Enums\Permission::CreateTickets->value)
                        <form method="POST" action="{{ route('tickets.fromInteraction', $interaction) }}">
                            @csrf
                            <button class="btn-primary">
                                <x-icon name="plus" class="h-4 w-4"/> Add to Ticket
                            </button>
                        </form>
                    @endcan
                @endif
            </div>

        </div>

        {{-- ============================ Right: who and handling ============== --}}
        <div class="space-y-6">

            {{-- The person --}}
            <div class="card p-5">
                <h2 class="mb-4 flex items-center gap-2 text-sm font-bold text-slate-800 dark:text-white">
                    <x-icon name="id-card" class="h-4 w-4 text-brand-500"/> Identitas
                </h2>

                @if ($contact)
                    <div class="flex items-center gap-3">
                        <span class="avatar h-11 w-11 text-sm">{{ $contact->initial() }}</span>
                        <div class="min-w-0">
                            <a href="{{ route('contacts.show', $contact) }}"
                               class="block truncate font-bold text-slate-800 hover:text-brand-600 dark:text-white">
                                {{ $contact->name() }}
                            </a>
                            <p class="text-xs text-slate-400">{{ $contact->code }}</p>
                        </div>
                    </div>

                    <div class="mt-4 space-y-2 text-sm">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-slate-400">Status</span>
                            <span class="{{ $contact->status->badge() }}">
                                <x-icon :name="$contact->status->icon()" class="h-3 w-3"/> {{ $contact->status->label() }}
                            </span>
                        </div>

                        @if ($contact->agent_code)
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-slate-400">Kode agent</span>
                                <span class="font-mono text-xs font-semibold text-slate-700 dark:text-slate-200">{{ $contact->agent_code }}</span>
                            </div>
                        @endif

                        <div class="flex items-center justify-between gap-2">
                            <span class="text-slate-400">WhatsApp</span>
                            @if ($contact->phone_e164)
                                <a href="{{ \App\Support\PhoneNumber::waLink($contact->phone_e164) }}" target="_blank" rel="noopener"
                                   class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-600 hover:underline dark:text-emerald-400">
                                    <x-icon name="whatsapp" class="h-3.5 w-3.5"/>
                                    {{ \App\Support\PhoneNumber::pretty($contact->phone_e164) }}
                                </a>
                            @else
                                <span class="text-xs text-slate-400">Belum ada</span>
                            @endif
                        </div>

                        @if ($contact->region)
                            <div class="flex items-start justify-between gap-2">
                                <span class="shrink-0 text-slate-400">Wilayah</span>
                                <span class="text-right text-xs text-slate-600 dark:text-slate-300">{{ $contact->region->label() }}</span>
                            </div>
                        @endif
                    </div>

                    <div class="mt-4 flex flex-wrap gap-1.5">
                        @foreach ($contact->identities as $identity)
                            <span class="badge-slate">
                                <x-icon :name="$identity->channel->icon()" class="h-3 w-3"/> {{ $identity->display() }}
                            </span>
                        @endforeach
                    </div>

                    {{-- The full contact form, revealed on demand.

                         It is the SAME component the contact page uses, so an
                         operator working from the inbox never has to go and
                         find another screen — and the two can never offer
                         different fields. Everything is optional; only
                         "Simpan & Jadikan Agent" needs a name and a number. --}}
                    @can(\App\Enums\Permission::ManageContacts->value)
                        @php
                            // Re-open automatically when a submit bounced back
                            // with errors, so the operator's typing is visible
                            // next to the message explaining it.
                            $formHasErrors = $errors->hasAny([
                                'full_name', 'phone', 'email', 'region_id', 'intent',
                            ]);
                        @endphp

                        <div x-data="{ open: @js((bool) $formHasErrors) }" class="mt-4">
                            <button type="button" x-show="!open" @click="open = true" class="btn-outline w-full">
                                <x-icon name="edit" class="h-4 w-4"/>
                                {{ $contact->isAgent() ? 'Ubah Data Kontak' : 'Lengkapi Data / Jadikan Agent' }}
                            </button>

                            <div x-show="open" x-cloak x-collapse>
                                <div class="mb-3 flex items-center justify-between gap-2">
                                    <p class="text-xs font-semibold text-slate-600 dark:text-slate-300">Data Diri</p>
                                    <button type="button" @click="open = false"
                                            class="text-xs text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                                        Tutup
                                    </button>
                                </div>

                                <x-contact-form :contact="$contact" :owners="$owners"
                                                :can-manage="true"
                                                :can-agent="auth()->user()->hasPermission(\App\Enums\Permission::ManageAgents)"/>
                            </div>
                        </div>
                    @endcan

                @else
                    {{-- No contact yet. The resolver is a button, not a
                         terminal command an operator cannot run. --}}
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Komentar ini belum tercocokkan ke kontak mana pun.
                    </p>

                    @error('contact') <p class="form-error mt-2">{{ $message }}</p> @enderror

                    @can(\App\Enums\Permission::HandleInteractions->value)
                        <form method="POST" action="{{ route('interactions.resolveContact', $interaction) }}" class="mt-3">
                            @csrf
                            <button class="btn-outline w-full">
                                <x-icon name="user-plus" class="h-4 w-4"/> Cocokkan ke Kontak
                            </button>
                        </form>
                        <p class="mt-2 text-[11px] text-slate-400">
                            Mencari kontak dengan username yang sama, atau membuat kontak baru.
                        </p>
                    @endcan
                @endif
            </div>

            {{-- Everything else this person has said --}}
            @if ($history->isNotEmpty())
                <div class="card p-5">
                    <h2 class="mb-3 text-sm font-bold text-slate-800 dark:text-white">
                        Riwayat orang ini
                        <span class="badge-slate ml-1">{{ number_format($historyTotal) }}</span>
                    </h2>

                    <div class="space-y-3">
                        @foreach ($history as $past)
                            <a href="{{ route('interactions.show', $past) }}" class="block rounded-xl bg-slate-50 p-3 transition hover:bg-slate-100 dark:bg-white/5 dark:hover:bg-white/10">
                                <p class="line-clamp-2 text-xs text-slate-600 dark:text-slate-300">{{ $past->shortText(120) }}</p>
                                <p class="mt-1.5 flex items-center gap-1.5">
                                    @if ($past->effectiveSentiment())
                                        <span class="{{ $past->effectiveSentiment()->badge() }}">{{ $past->effectiveSentiment()->label() }}</span>
                                    @endif
                                    <span class="text-[11px] text-slate-400">{{ $past->occurred_at?->diffForHumans() }}</span>
                                </p>
                            </a>
                        @endforeach
                    </div>

                    @if ($historyTotal > $history->count())
                        {{-- Panel ini hanya memuat sepuluh; sisanya ada di halaman
                             kontak, bukan berhenti diam tanpa penjelasan. --}}
                        <a href="{{ route('contacts.show', $interaction->contact) }}"
                           class="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">
                            Lihat seluruh {{ number_format($historyTotal) }} interaksi
                            <x-icon name="chevron-right" class="h-3 w-3"/>
                        </a>
                    @endif
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
