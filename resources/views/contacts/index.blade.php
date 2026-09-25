<x-layouts.app title="Database Kontak">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Database Kontak (UID)</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Satu baris = satu orang, meski punya banyak akun di kanal berbeda.
            </p>
        </div>

        <a href="{{ route('contacts.agents') }}" class="btn-outline">
            <x-icon name="badge-check" class="h-4 w-4"/> Lihat Agent
        </a>
    </x-slot:header>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Total kontak" :value="number_format($stats['total'])" icon="users" tone="brand"/>
        <x-stat-card label="Agent aktif" :value="number_format($stats['agents'])" icon="badge-check" tone="emerald"
                     :href="route('contacts.agents')"/>
        <x-stat-card label="Calon agent" :value="number_format($stats['candidates'])" icon="user-plus" tone="amber"/>
        <x-stat-card label="Punya nomor WA" :value="number_format($stats['with_phone'])" icon="whatsapp" tone="cyan"
                     :hint="$stats['total'] > 0 ? round($stats['with_phone'] / $stats['total'] * 100).'% dari total' : null"/>
    </div>

    {{-- Filters --}}
    <form method="GET" class="card mb-6 flex flex-col gap-3 p-4 lg:flex-row lg:items-end">
        <div class="flex-1">
            <label for="q" class="label">Cari nama, UID, nomor, atau username</label>
            <div class="relative">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"/>
                <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="input pl-9" placeholder="Kata kunci…">
            </div>
        </div>

        <div class="lg:w-44">
            <label for="status" class="label">Status</label>
            <select id="status" name="status" class="input">
                <option value="">Semua status</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="lg:w-52">
            <label for="region" class="label">Provinsi</label>
            <select id="region" name="region" class="input">
                <option value="">Semua wilayah</option>
                @foreach ($provinces as $province)
                    <option value="{{ $province->id }}" @selected((string) ($filters['region'] ?? '') === (string) $province->id)>{{ $province->name }}</option>
                @endforeach
            </select>
        </div>

        <label class="flex items-center gap-2 pb-2.5 text-sm text-slate-600 dark:text-slate-300">
            <input type="checkbox" name="has_phone" value="1" @checked(($filters['has_phone'] ?? '') === '1')
                   class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-white/20 dark:bg-ink-850">
            Ada WA
        </label>

        <div class="flex gap-2">
            <button class="btn-primary"><x-icon name="filter" class="h-4 w-4"/> Terapkan</button>
            @if (array_filter($filters))
                <a href="{{ route('contacts.index') }}" class="btn-outline"><x-icon name="x" class="h-4 w-4"/></a>
            @endif
        </div>
    </form>

    <div class="mb-3 flex items-center justify-between">
        <h2 class="text-base font-bold text-slate-800 dark:text-white">
            {{ number_format($contacts->total()) }} kontak
        </h2>
        <span class="text-xs text-slate-400">Diurutkan: potensi tertinggi dulu</span>
    </div>

    @if ($contacts->isEmpty())
        <div class="card">
            <x-empty-state icon="users" title="Belum ada kontak"
                           description="Kontak terbentuk otomatis dari komentar yang masuk. Jalankan sinkron komentar dulu."/>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($contacts as $contact)
                <a href="{{ route('contacts.show', $contact) }}" class="card-glow group block p-4">
                    <div class="flex items-start gap-3">
                        <span class="avatar relative h-11 w-11 shrink-0 overflow-hidden text-sm">
                            {{ $contact->initial() }}
                            @if ($contact->avatar())
                                <img src="{{ $contact->avatar() }}" alt="" loading="lazy" referrerpolicy="no-referrer"
                                     class="absolute inset-0 h-full w-full object-cover" onerror="this.remove()">
                            @endif
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="truncate font-bold text-slate-800 group-hover:text-brand-600 dark:text-white">
                                {{ $contact->name() }}
                            </p>
                            <p class="font-mono text-[11px] text-slate-400">{{ $contact->code }}</p>
                        </div>

                        <span class="{{ $contact->status->badge() }} shrink-0">
                            <x-icon :name="$contact->status->icon()" class="h-3 w-3"/>
                            {{ $contact->status->label() }}
                        </span>
                    </div>

                    {{-- Channel handles: the whole point of the UID, so they get
                         prominence rather than being buried in the detail page. --}}
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        @forelse ($contact->identities as $identity)
                            <span class="badge-slate">
                                <x-icon :name="$identity->channel->icon()" class="h-3 w-3"/>
                                <span class="max-w-[9rem] truncate">{{ $identity->display() }}</span>
                            </span>
                        @empty
                            <span class="text-xs text-slate-400">Belum ada akun terhubung</span>
                        @endforelse
                    </div>

                    <div class="mt-3 flex items-center justify-between gap-2 border-t border-slate-100 pt-3 text-xs dark:border-white/5">
                        <span class="truncate text-slate-400">
                            @if ($contact->region)
                                <x-icon name="map-pin" class="inline h-3 w-3"/> {{ $contact->region->name }}
                            @else
                                <span class="italic">Wilayah belum diisi</span>
                            @endif
                        </span>

                        <span class="flex shrink-0 items-center gap-2">
                            @if ($contact->potential_score > 0)
                                <span class="badge-violet">{{ $contact->potential_score }}%</span>
                            @endif
                            <span class="text-slate-400">{{ $contact->interactions_count }} interaksi</span>
                        </span>
                    </div>
                </a>
            @endforeach
        </div>

        @if ($contacts->hasPages())
            <div class="mt-6">{{ $contacts->links() }}</div>
        @endif
    @endif
</x-layouts.app>
