@php
    $canManage = auth()->user()->can(\App\Enums\Permission::ManageContacts->value);
    $canAgent  = auth()->user()->can(\App\Enums\Permission::ManageAgents->value);
@endphp

<x-layouts.app :title="$contact->name()">
    <x-slot:header>
        <div class="flex min-w-0 items-center gap-3">
            <span class="avatar relative h-12 w-12 shrink-0 overflow-hidden text-base">
                {{ $contact->initial() }}
                @if ($contact->avatar())
                    <img src="{{ $contact->avatar() }}" alt="" loading="lazy" referrerpolicy="no-referrer"
                         class="absolute inset-0 h-full w-full object-cover" onerror="this.remove()">
                @endif
            </span>
            <div class="min-w-0">
                <h1 class="truncate text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">
                    {{ $contact->name() }}
                </h1>
                <p class="mt-0.5 flex flex-wrap items-center gap-2 text-sm text-slate-400">
                    <span class="font-mono">{{ $contact->code }}</span>
                    <span class="{{ $contact->status->badge() }}">
                        <x-icon :name="$contact->status->icon()" class="h-3 w-3"/> {{ $contact->status->label() }}
                    </span>
                    @if ($contact->agent_code)
                        <span class="badge-green font-mono">{{ $contact->agent_code }}</span>
                    @endif
                </p>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            @if ($contact->phone_e164)
                <a href="{{ \App\Support\PhoneNumber::waLink($contact->phone_e164) }}" target="_blank" rel="noopener" class="btn-success">
                    <x-icon name="whatsapp" class="h-4 w-4"/> Chat WA
                </a>
            @endif

            @if ($canAgent)
                @if ($contact->isAgent())
                    <form method="POST" action="{{ route('contacts.demote', $contact) }}"
                          onsubmit="return confirm('Cabut status agent dari {{ $contact->name() }}?')">
                        @csrf
                        @method('DELETE')
                        <button class="btn-outline"><x-icon name="ban" class="h-4 w-4"/> Cabut Agent</button>
                    </form>
                @else
                    @php $agentReady = filled($contact->full_name) && filled($contact->phone_e164); @endphp

                    <form method="POST" action="{{ route('contacts.promote', $contact) }}">
                        @csrf
                        <button class="btn-primary"
                                title="{{ $agentReady ? 'Jadikan agent' : 'Lengkapi nama asli dan nomor WhatsApp di form di bawah dulu' }}">
                            <x-icon name="badge-check" class="h-4 w-4"/> Jadikan Agent
                        </button>
                    </form>
                @endif
            @endif
        </div>
    </x-slot:header>

    @if ($errors->hasAny(['agent', 'full_name', 'phone']))
        <div class="mb-6 flex items-start gap-2 rounded-2xl border border-rose-500/30 bg-rose-500/[0.06] p-4 text-sm text-rose-600 dark:text-rose-400">
            <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0"/>
            <span>
                {{ $errors->first('agent') ?: 'Belum bisa dijadikan agent — lengkapi dulu data yang ditandai pada form di bawah.' }}
            </span>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ==================== Left: the operator's data entry ============== --}}
        <div class="space-y-6 lg:col-span-2">

            <div class="card p-5">
                <h2 class="mb-4 flex items-center gap-2 text-sm font-bold text-slate-800 dark:text-white">
                    <x-icon name="id-card" class="h-4 w-4 text-brand-500"/> Data Diri
                    @unless ($canManage)
                        <span class="badge-slate ml-auto">Hanya baca</span>
                    @endunless
                </h2>

                <x-contact-form :contact="$contact" :owners="$owners"
                                :can-manage="$canManage" :can-agent="$canAgent"/>
            </div>

            {{-- Everything this person has said to us --}}
            <div class="card overflow-hidden">
                <div class="flex items-center justify-between p-5 pb-3">
                    <h2 class="flex items-center gap-2 text-sm font-bold text-slate-800 dark:text-white">
                        <x-icon name="message" class="h-4 w-4 text-brand-500"/> Riwayat Interaksi
                        <span class="badge-slate">{{ number_format($interactions->total()) }}</span>
                    </h2>
                </div>

                <div class="divide-y divide-slate-100 dark:divide-white/5">
                    @forelse ($interactions as $interaction)
                        <x-interaction-row :interaction="$interaction"/>
                    @empty
                        <x-empty-state icon="message" title="Belum ada interaksi tercatat" class="!py-10"/>
                    @endforelse
                </div>

                @if ($interactions->hasPages())
                    <div class="p-5">{{ $interactions->links() }}</div>
                @endif
            </div>
        </div>

        {{-- ==================== Right: accounts and history ================== --}}
        <div class="space-y-6">

            <div class="card p-5">
                <h2 class="mb-4 flex items-center gap-2 text-sm font-bold text-slate-800 dark:text-white">
                    <x-icon name="at-sign" class="h-4 w-4 text-brand-500"/> Akun Terhubung
                </h2>

                <div class="space-y-2">
                    @forelse ($contact->identities as $identity)
                        <div class="flex items-center gap-3 rounded-xl bg-slate-50 p-3 dark:bg-white/5">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-white text-slate-500 dark:bg-white/10 dark:text-slate-300">
                                <x-icon :name="$identity->channel->icon()" class="h-4 w-4"/>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $identity->display() }}</p>
                                <p class="text-[11px] text-slate-400">{{ $identity->channel->label() }}</p>
                            </div>
                            @if ($identity->verified_at)
                                <x-icon name="badge-check" class="h-4 w-4 shrink-0 text-sky-500"/>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-slate-400">Belum ada akun terhubung.</p>
                    @endforelse
                </div>
            </div>

            <div class="card p-5">
                <h2 class="mb-4 text-sm font-bold text-slate-800 dark:text-white">Ringkasan</h2>

                <dl class="space-y-2.5 text-sm">
                    <div class="flex justify-between gap-2">
                        <dt class="text-slate-400">Pertama terlihat</dt>
                        <dd class="text-slate-600 dark:text-slate-300">{{ $contact->first_seen_at?->translatedFormat('d M Y') ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-slate-400">Terakhir aktif</dt>
                        <dd class="text-slate-600 dark:text-slate-300">{{ $contact->last_seen_at?->diffForHumans() ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-slate-400">Penanggung jawab</dt>
                        <dd class="text-slate-600 dark:text-slate-300">{{ $contact->owner?->name ?? '—' }}</dd>
                    </div>
                    @if ($contact->isAgent())
                        <div class="flex justify-between gap-2">
                            <dt class="text-slate-400">Diangkat oleh</dt>
                            <dd class="text-slate-600 dark:text-slate-300">{{ $contact->recruiter?->name ?? '—' }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            @if ($contact->followUps->isNotEmpty())
                <div class="card p-5">
                    <h2 class="mb-3 flex items-center gap-2 text-sm font-bold text-slate-800 dark:text-white">
                        Follow-up ke orang ini
                        <span class="badge-slate">{{ $contact->followUps->count() }}&times;</span>
                    </h2>

                    <div class="space-y-3">
                        @foreach ($contact->followUps->take(8) as $followUp)
                            <div class="border-l-2 border-brand-200 pl-3 dark:border-brand-500/30">
                                <p class="text-xs">
                                    <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $followUp->user->name }}</span>
                                    <span class="text-slate-400">· {{ $followUp->created_at->diffForHumans() }}</span>
                                </p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $followUp->action->label() }}</p>
                            </div>
                        @endforeach
                    </div>

                    @if ($contact->followUps->count() > 8)
                        {{-- Delapan terbaru saja yang muat; katakan ada berapa lagi
                             daripada memotong diam-diam. --}}
                        <p class="mt-3 text-xs text-slate-400">
                            &plus; {{ $contact->followUps->count() - 8 }} follow-up lebih lama,
                            tercatat lengkap pada tiap interaksinya.
                        </p>
                    @endif
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
