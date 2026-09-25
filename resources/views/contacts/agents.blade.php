<x-layouts.app title="Agent">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Register Agent</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Kontak yang sudah diangkat menjadi agent, lengkap dengan kode dan wilayahnya.
            </p>
        </div>

        <a href="{{ route('contacts.index') }}" class="btn-outline">
            <x-icon name="users" class="h-4 w-4"/> Semua Kontak
        </a>
    </x-slot:header>

    <form method="GET" class="card mb-6 flex gap-3 p-4">
        <div class="flex-1">
            <label for="q" class="label">Cari agent</label>
            <div class="relative">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"/>
                <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="input pl-9" placeholder="Nama, kode, atau nomor…">
            </div>
        </div>
        <div class="flex items-end gap-2">
            <button class="btn-primary"><x-icon name="search" class="h-4 w-4"/> Cari</button>
            @if ($filters['q'] ?? null)
                <a href="{{ route('contacts.agents') }}" class="btn-outline"><x-icon name="x" class="h-4 w-4"/></a>
            @endif
        </div>
    </form>

    @if ($agents->isEmpty())
        <div class="card">
            <x-empty-state icon="badge-check" title="Belum ada agent"
                           description="Buka detail kontak yang potensial, lengkapi nama asli dan nomor WA, lalu tekan “Jadikan Agent”."/>
        </div>
    @else
        <div class="card overflow-hidden">
            {{-- Phone: one card per agent. The desktop table is six columns
                 wide, and sideways-swiping to read a single person is not a
                 rendering anyone chooses. --}}
            <div class="divide-y divide-slate-100 md:hidden dark:divide-white/5">
                @foreach ($agents as $agent)
                    <div class="p-4">
                        <a href="{{ route('contacts.show', $agent) }}" class="flex items-center gap-3">
                            <span class="avatar h-10 w-10 shrink-0 text-xs">{{ $agent->initial() }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-semibold text-slate-800 dark:text-white">
                                    {{ $agent->name() }}
                                </span>
                                <span class="block font-mono text-[11px] text-slate-400">{{ $agent->code }}</span>
                            </span>
                            <span class="badge-green shrink-0 font-mono">{{ $agent->agent_code }}</span>
                        </a>

                        <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs">
                            @if ($agent->phone_e164)
                                <a href="{{ \App\Support\PhoneNumber::waLink($agent->phone_e164) }}" target="_blank" rel="noopener"
                                   class="inline-flex items-center gap-1 font-semibold text-emerald-600 dark:text-emerald-400">
                                    <x-icon name="whatsapp" class="h-3.5 w-3.5"/>
                                    {{ \App\Support\PhoneNumber::pretty($agent->phone_e164) }}
                                </a>
                            @endif

                            @if ($agent->region)
                                <span class="text-slate-500 dark:text-slate-400">{{ $agent->region->label() }}</span>
                            @endif
                        </div>

                        @if ($agent->identities->isNotEmpty())
                            <div class="mt-2 flex flex-wrap gap-1">
                                @foreach ($agent->identities as $identity)
                                    <span class="badge-slate">
                                        <x-icon :name="$identity->channel->icon()" class="h-3 w-3"/>
                                        <span class="max-w-[8rem] truncate">{{ $identity->display() }}</span>
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        <p class="mt-2 text-[11px] text-slate-400">
                            Agent sejak {{ $agent->agent_since?->translatedFormat('d M Y') ?? '—' }}
                            @if ($agent->recruiter) · oleh {{ $agent->recruiter->name }} @endif
                        </p>
                    </div>
                @endforeach
            </div>

            <div class="hidden overflow-x-auto md:block">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 dark:border-white/5">
                            <th class="th">Agent</th>
                            <th class="th">Kode</th>
                            <th class="th">Kontak</th>
                            <th class="th">Wilayah</th>
                            <th class="th">Akun</th>
                            <th class="th text-right">Sejak</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                        @foreach ($agents as $agent)
                            <tr class="row-hover">
                                <td class="td">
                                    <a href="{{ route('contacts.show', $agent) }}" class="flex items-center gap-3">
                                        <span class="avatar h-9 w-9 shrink-0 text-xs">{{ $agent->initial() }}</span>
                                        <span class="min-w-0">
                                            <span class="block truncate font-semibold text-slate-700 hover:text-brand-600 dark:text-slate-200">
                                                {{ $agent->name() }}
                                            </span>
                                            <span class="block font-mono text-[11px] text-slate-400">{{ $agent->code }}</span>
                                        </span>
                                    </a>
                                </td>

                                <td class="td">
                                    <span class="badge-green font-mono">{{ $agent->agent_code }}</span>
                                </td>

                                <td class="td">
                                    @if ($agent->phone_e164)
                                        <a href="{{ \App\Support\PhoneNumber::waLink($agent->phone_e164) }}" target="_blank" rel="noopener"
                                           class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-600 hover:underline dark:text-emerald-400">
                                            <x-icon name="whatsapp" class="h-3.5 w-3.5"/>
                                            {{ \App\Support\PhoneNumber::pretty($agent->phone_e164) }}
                                        </a>
                                    @else
                                        <span class="text-xs text-slate-400">—</span>
                                    @endif
                                </td>

                                <td class="td">
                                    <span class="text-xs text-slate-500 dark:text-slate-400">
                                        {{ $agent->region?->label() ?? '—' }}
                                    </span>
                                </td>

                                <td class="td">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($agent->identities as $identity)
                                            <span class="badge-slate">
                                                <x-icon :name="$identity->channel->icon()" class="h-3 w-3"/>
                                                <span class="max-w-[7rem] truncate">{{ $identity->display() }}</span>
                                            </span>
                                        @endforeach
                                    </div>
                                </td>

                                <td class="td text-right">
                                    <span class="text-xs text-slate-500 dark:text-slate-400">
                                        {{ $agent->agent_since?->translatedFormat('d M Y') ?? '—' }}
                                    </span>
                                    @if ($agent->recruiter)
                                        <span class="block text-[11px] text-slate-400">oleh {{ $agent->recruiter->name }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-6">{{ $agents->links() }}</div>
    @endif
</x-layouts.app>
