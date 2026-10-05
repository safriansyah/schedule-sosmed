{{-- The queue for the chosen dates, as cards. Re-fetched by the page every
     few seconds and swapped in place; the buttons talk to the parent's
     act() / open*() and the modals live on the page, outside this partial,
     so a refresh never closes a form someone is filling in. --}}
@php use App\Enums\GuestBookStatus; use App\Enums\GuestBookService; @endphp

<div class="mb-4 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-5">
    <x-stat-card label="Total" :value="number_format($counts['total'])" icon="id-card" tone="brand"/>
    <x-stat-card label="Menunggu" :value="number_format($counts['waiting'])" icon="clock" tone="amber"/>
    <x-stat-card label="Dipanggil / dilayani" :value="number_format($counts['serving'])" icon="headset" tone="cyan"/>
    <x-stat-card label="Selesai" :value="number_format($counts['done'])" icon="check-circle" tone="emerald"/>
    <x-stat-card label="Jadi tiket" :value="number_format($counts['ticketed'])" icon="file-text" tone="violet"/>
</div>

@if ($truncated)
    <p class="mb-3 rounded-xl bg-amber-500/10 p-3 text-xs text-amber-700 dark:text-amber-300">
        Menampilkan 300 antrian pertama. Persempit tanggal atau pencarian, atau unduh XLSX untuk data lengkap.
    </p>
@endif

@forelse ($entries as $entry)
    @php
        $status = $entry->status;
        $statusUrl = route('guest-book.admin.status', $entry);
        $payload = $entry->adminPayload();
        $complaint = $entry->service->type() === GuestBookService::TYPE_COMPLAINT;
    @endphp

    <div class="card mb-3 p-4 sm:p-5 {{ $status === GuestBookStatus::Called ? '!border-amber-400/50' : '' }} {{ $status === GuestBookStatus::Serving ? '!border-cyan-400/50' : '' }}">
        <div class="flex flex-wrap items-start gap-4">
            <div class="grid h-16 w-16 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-brand-600 to-accent-500 font-mono text-2xl font-extrabold text-white">
                {{ $entry->displayNumber() }}
            </div>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="font-bold text-slate-800 dark:text-white">{{ $entry->name }}</p>
                    <span class="{{ $status->badge() }}">{{ $status->label() }}</span>
                    @if ($entry->ticket)
                        <a href="{{ route('tickets.show', $entry->ticket) }}" class="badge-violet hover:underline">
                            <x-icon name="file-text" class="h-3 w-3"/> {{ $entry->ticket->number }}
                        </a>
                    @endif
                    @if (! $entry->queue_date->isSameDay(now('Asia/Jakarta')))
                        <span class="badge-slate">{{ $entry->queue_date->translatedFormat('d M Y') }}</span>
                    @endif
                </div>

                <p class="mt-1 flex flex-wrap items-center gap-1.5 text-xs font-semibold">
                    <span class="{{ $complaint ? 'badge-red' : 'badge-blue' }}">{{ $entry->service->typeLabel() }}</span>
                    <span class="text-brand-600 dark:text-brand-300">{{ $entry->service->label() }}</span>
                </p>

                <dl class="mt-2 grid gap-x-5 gap-y-1 text-xs text-slate-500 sm:grid-cols-2 lg:grid-cols-4 dark:text-slate-400">
                    <div>
                        <dt class="inline text-slate-400">WA:</dt>
                        @if ($wa = \App\Support\PhoneNumber::waLink($entry->whatsapp))
                            <a href="{{ $wa }}" target="_blank" rel="noopener" class="font-medium text-emerald-600 hover:underline">{{ \App\Support\PhoneNumber::pretty($entry->whatsapp) ?? $entry->whatsapp }}</a>
                        @else
                            <dd class="inline">{{ $entry->whatsapp }}</dd>
                        @endif
                    </div>
                    <div><dt class="inline text-slate-400">HP:</dt> <dd class="inline">{{ \App\Support\PhoneNumber::pretty($entry->phone) ?? $entry->phone }}</dd></div>
                    <div><dt class="inline text-slate-400">NIM:</dt> <dd class="inline font-mono">{{ $entry->nim ?? '—' }}</dd></div>
                    <div><dt class="inline text-slate-400">Jenis kelamin:</dt> <dd class="inline">{{ $entry->gender->label() }}</dd></div>
                </dl>

                @if ($entry->description)
                    <p class="mt-2 line-clamp-3 whitespace-pre-line break-words rounded-xl bg-slate-50 p-2.5 text-sm text-slate-600 dark:bg-white/5 dark:text-slate-300">{{ $entry->description }}</p>
                @endif

                {{-- What "Selesai" recorded. --}}
                @if ($status === GuestBookStatus::Done && $entry->service_process)
                    <p class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl bg-emerald-500/[0.07] px-3 py-2 text-xs text-emerald-800 dark:text-emerald-300">
                        <span><span class="opacity-70">Proses:</span> <strong>{{ $payload['process'] }}</strong></span>
                        <span><span class="opacity-70">Penyelesaian:</span> <strong>{{ $payload['resolution'] }}</strong></span>
                        <span><span class="opacity-70">Diselesaikan oleh:</span> <strong>{{ $payload['completed_by'] ?? '—' }}</strong></span>
                    </p>
                @endif

                <p class="mt-2 text-[11px] text-slate-400">
                    Daftar {{ $entry->created_at->timezone('Asia/Jakarta')->format('H:i') }}
                    @if ($entry->called_at) · dipanggil {{ $entry->called_at->timezone('Asia/Jakarta')->format('H:i') }} @endif
                    @if ($entry->finished_at) · selesai {{ $entry->finished_at->timezone('Asia/Jakarta')->format('H:i') }} @endif
                </p>
            </div>

            @if ($entry->signature_path)
                <a href="{{ route('guest-book.admin.signature', $entry) }}" target="_blank" rel="noopener"
                   class="hidden shrink-0 rounded-xl border border-slate-200 bg-white p-1 sm:block dark:border-white/10" title="Paraf">
                    <img src="{{ route('guest-book.admin.signature', $entry) }}" alt="Paraf {{ $entry->name }}" loading="lazy"
                         class="h-14 w-28 object-contain dark:invert">
                </a>
            @endif
        </div>

        {{-- Actions --}}
        <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3 dark:border-white/5">
            <button type="button" class="btn-ghost btn-sm" @click="openDetail(@js($payload))">
                <x-icon name="eye" class="h-3.5 w-3.5"/> Detail
            </button>

            @unless ($entry->hasTicket())
                @if ($status === GuestBookStatus::Waiting)
                    <button type="button" class="btn-primary btn-sm" @click="act(@js($statusUrl), { status: 'called' })">
                        <x-icon name="bell" class="h-3.5 w-3.5"/> Panggil
                    </button>
                @endif
                @if (in_array($status, [GuestBookStatus::Waiting, GuestBookStatus::Called], true))
                    <button type="button" class="btn-outline btn-sm" @click="act(@js($statusUrl), { status: 'serving' })">
                        <x-icon name="headset" class="h-3.5 w-3.5"/> Layani
                    </button>
                @endif
                @if ($status->isActive())
                    <button type="button" class="btn-success btn-sm" @click="openComplete(@js($payload))">
                        <x-icon name="check" class="h-3.5 w-3.5"/> Selesai
                    </button>
                    <button type="button" class="btn-outline btn-sm" @click="act(@js($statusUrl), { status: 'skipped' }, 'Lewati antrian {{ $entry->displayNumber() }}?')">
                        <x-icon name="chevron-right" class="h-3.5 w-3.5"/> Lewati
                    </button>
                @else
                    <button type="button" class="btn-outline btn-sm" @click="act(@js($statusUrl), { status: 'waiting' }, 'Kembalikan antrian {{ $entry->displayNumber() }} ke antrean?')">
                        <x-icon name="rotate" class="h-3.5 w-3.5"/> Kembalikan ke antrean
                    </button>
                @endif

                @if ($canTicket)
                    <button type="button" class="btn-primary btn-sm ml-auto" @click="openTicket(@js($payload))">
                        <x-icon name="plus" class="h-3.5 w-3.5"/> Add Ticket
                    </button>
                @endif
            @endunless
        </div>
    </div>
@empty
    <div class="card">
        <x-empty-state icon="id-card" title="Tidak ada antrian"
                       description="Tidak ada tamu pada tanggal dan pencarian ini. Antrian baru akan muncul sendiri di sini."/>
    </div>
@endforelse
