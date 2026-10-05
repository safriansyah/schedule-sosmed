@php
    // Which queue you are looking at, named in the heading and the browser tab.
    // Without it every tab rendered the same title, so arriving from a sidebar
    // badge told you a number but not which filter produced it — and the tab
    // bar sits below the fold on a phone.
    $tabLabel = $tabs[$tab][0] ?? null;
@endphp

<x-layouts.app :title="'Inbox Interaksi'.($tabLabel ? ' — '.$tabLabel : '')">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">
                Inbox Interaksi
                @if ($tabLabel)
                    <span class="font-bold text-slate-400">({{ $tabLabel }})</span>
                @endif
            </h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Komentar &amp; pesan dari semua kanal, dinilai otomatis lalu ditangani.
            </p>
        </div>

        @can(\App\Enums\Permission::HandleInteractions->value)
            <a href="{{ route('interactions.manual') }}" class="btn-outline">
                <x-icon name="plus" class="h-4 w-4"/> Catat Manual
            </a>

            <form method="POST" action="{{ route('interactions.classify') }}">
                @csrf
                <button class="btn-outline" @if ($stats['unclassified'] === 0) disabled @endif>
                    <x-icon name="robot" class="h-4 w-4"/>
                    Nilai sekarang
                    @if ($stats['unclassified'] > 0)
                        <span class="badge-amber ml-1">{{ number_format($stats['unclassified']) }}</span>
                    @endif
                </button>
            </form>
        @endcan
    </x-slot:header>

    {{-- Headline numbers. SLA breaches come first because that is the only one
         that demands action today. --}}
    <div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <x-stat-card label="Lewat batas waktu" :value="number_format($stats['sla_breach'])"
                     icon="alert" tone="rose" :tint="$stats['sla_breach'] > 0"
                     hint="Mendesak & belum dibalas > {{ config('crm.sla.urgent_hours') }} jam"/>

        <x-stat-card label="Positif (7 hari)" :value="number_format($stats['positive'])"
                     icon="thumbs-up" tone="emerald"
                     :hint="$stats['period_total'] > 0 ? round($stats['positive'] / $stats['period_total'] * 100).'% dari total' : 'Belum ada data'"/>

        <x-stat-card label="Negatif (7 hari)" :value="number_format($stats['negative'])"
                     icon="thumbs-down" tone="amber"
                     :hint="$stats['period_total'] > 0 ? round($stats['negative'] / $stats['period_total'] * 100).'% dari total' : 'Belum ada data'"/>

        <x-stat-card label="Total 7 hari" :value="number_format($stats['period_total'])"
                     icon="inbox" tone="brand"
                     hint="{{ number_format($stats['neutral']) }} netral"/>
    </div>

    {{-- Saved views --}}
    <div class="mb-4 flex flex-wrap gap-2">
        @foreach ($tabs as $key => [$label, $icon, $tone, $description])
            @php
                $active = $tab === $key;
                $count  = $counts[$key] ?? 0;
            @endphp
            <a href="{{ route('interactions.index', array_filter(['tab' => $key, 'view' => $view === 'account' ? 'account' : null])) }}"
               title="{{ $description }}"
               @class([
                   'inline-flex items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-semibold transition',
                   'bg-brand-600 text-white shadow-[0_8px_20px_-10px_rgb(124_58_237/0.8)]' => $active,
                   'bg-white text-slate-500 hover:text-slate-900 ring-1 ring-slate-200 hover:ring-slate-300 dark:bg-white/5 dark:text-slate-400 dark:ring-white/10 dark:hover:text-white' => ! $active,
               ])>
                <x-icon :name="$icon" class="h-4 w-4"/>
                {{ $label }}
                @if ($count > 0)
                    <span @class([
                        'grid h-5 min-w-5 place-items-center rounded-full px-1.5 text-[10px] font-bold',
                        'bg-white/25 text-white' => $active,
                        'bg-rose-500 text-white' => ! $active && $key === 'urgent',
                        'bg-slate-200 text-slate-600 dark:bg-white/10 dark:text-slate-300' => ! $active && $key !== 'urgent',
                    ])>{{ $count > 99 ? '99+' : $count }}</span>
                @endif
            </a>
        @endforeach
    </div>

    <div class="mb-4 flex items-start gap-2 text-xs text-slate-500 dark:text-slate-400">
        <x-icon name="help-circle" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400"/>
        <p>{{ $tabs[$tab][3] ?? '' }}</p>
    </div>

    {{-- Filters --}}
    <form method="GET" class="card mb-6 flex flex-col gap-3 p-4 lg:flex-row lg:items-end">
        <input type="hidden" name="tab" value="{{ $tab }}">
        @if ($view === 'account') <input type="hidden" name="view" value="account"> @endif

        <div class="flex-1">
            <label for="q" class="label">Cari isi komentar / nama</label>
            <div class="relative">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"/>
                <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="input pl-9" placeholder="Kata kunci…">
            </div>
        </div>

        @if (count($channels) > 1)
            <div class="lg:w-44">
                <label for="channel" class="label">Kanal</label>
                <select id="channel" name="channel" class="input">
                    <option value="">Semua kanal</option>
                    @foreach ($channels as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['channel'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @if ($canSeeAllTasks)
            {{-- Supervisors only: this is how you isolate one person's
                 workload. Hidden for field roles, and the controller ignores
                 the parameter for them too. --}}
            <div class="lg:w-44">
                <label for="handler" class="label">Petugas</label>
                <select id="handler" name="handler" class="input">
                    <option value="">Semua petugas</option>
                    @foreach ($assignees as $assignee)
                        <option value="{{ $assignee->id }}" @selected((string) ($filters['handler'] ?? '') === (string) $assignee->id)>
                            {{ $assignee->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        <div class="lg:w-40">
            <label for="sentiment" class="label">Sentimen</label>
            <select id="sentiment" name="sentiment" class="input">
                <option value="">Semua</option>
                @foreach ($sentiments as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['sentiment'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="lg:w-44">
            <label for="intent" class="label">Jenis</label>
            <select id="intent" name="intent" class="input">
                <option value="">Semua</option>
                @foreach ($intents as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['intent'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex gap-2">
            <button class="btn-primary"><x-icon name="filter" class="h-4 w-4"/> Terapkan</button>
            @if (array_filter(\Illuminate\Support\Arr::except($filters, ['tab', 'view'])))
                <a href="{{ route('interactions.index', array_filter(['tab' => $tab, 'view' => $view === 'account' ? 'account' : null])) }}" class="btn-outline" title="Reset filter">
                    <x-icon name="x" class="h-4 w-4"/>
                </a>
            @endif
        </div>
    </form>

    @php $canHandle = auth()->user()->can(\App\Enums\Permission::HandleInteractions->value); @endphp

    {{-- Per komentar / Per akun. Same tab and filters either way. --}}
    <div class="mb-3 inline-flex gap-1 rounded-xl bg-slate-100 p-1 dark:bg-white/5">
        @foreach (['list' => ['Per komentar', 'message'], 'account' => ['Per akun', 'users']] as $key => [$label, $icon])
            <a href="{{ route('interactions.index', array_filter(['view' => $key === 'account' ? 'account' : null] + \Illuminate\Support\Arr::except($filters, 'view'))) }}"
               @class([
                   'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                   'bg-white text-brand-700 shadow-sm dark:bg-ink-850 dark:text-brand-300' => $view === $key,
                   'text-slate-500 hover:text-slate-700 dark:text-slate-400' => $view !== $key,
               ])>
                <x-icon :name="$icon" class="h-3.5 w-3.5"/> {{ $label }}
            </a>
        @endforeach
    </div>

    @if ($view === 'account')
        @include('interactions.partials.accounts')
    @else

    {{-- List. Wrapped in the bulk form so the checkboxes submit as ids[] with
         no JavaScript involved — Alpine only drives the select-all box and
         whether the action bar is visible. --}}
    <form method="POST" action="{{ route('interactions.bulk') }}"
          x-data="{ selected: [], get all() { return this.selected.length === {{ $interactions->count() }} && this.selected.length > 0 } }">
        @csrf

        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <h2 class="flex items-center gap-3 text-base font-bold text-slate-800 dark:text-white">
                @if ($canHandle && $interactions->isNotEmpty())
                    <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-500 dark:text-slate-400">
                        <input type="checkbox" :checked="all"
                               @change="selected = $event.target.checked
                                    ? [...$el.closest('form').querySelectorAll('input[name='ids[]']')].map(i => i.value)
                                    : []"
                               class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-white/20 dark:bg-ink-850">
                        Pilih semua
                    </label>
                @endif
                {{ number_format($interactions->total()) }} interaksi
            </h2>
            <span class="text-xs text-slate-400">Diurutkan: mendesak dulu, lalu terbaru</span>
        </div>

        {{-- Action bar. Sticks to the bottom so it stays reachable while
             scrolling a long page, and only exists once something is picked. --}}
        @if ($canHandle)
            <div x-show="selected.length" x-cloak x-transition
                 class="sticky bottom-4 z-20 mb-3 flex flex-wrap items-center gap-2 rounded-2xl border border-brand-500/30
                        bg-white/95 p-3 shadow-lg backdrop-blur dark:bg-ink-850/95">
                <span class="px-1 text-sm font-semibold text-slate-700 dark:text-slate-200">
                    <span x-text="selected.length"></span> dipilih
                </span>

                @can(\App\Enums\Permission::AssignInteractions->value)
                    <select name="assigned_to" class="input !w-auto !py-2">
                        <option value="">Tugaskan ke…</option>
                        @foreach ($assignees as $user)
                            <option value="{{ $user->id }}">{{ $user->name }}</option>
                        @endforeach
                    </select>
                    <button name="action" value="assign" class="btn-outline !py-2">
                        <x-icon name="user-plus" class="h-4 w-4"/> Tugaskan
                    </button>
                @endcan

                <button name="action" value="done" class="btn-success !py-2">
                    <x-icon name="check-circle" class="h-4 w-4"/> Selesai
                </button>

                <button name="action" value="close" class="btn-outline !py-2"
                        title="Penanganan selesai. Riwayat tetap tersimpan; follow up baru membukanya lagi.">
                    <x-icon name="check" class="h-4 w-4"/> Close
                </button>

                <button name="action" value="ignore" class="btn-outline !py-2"
                        title="Untuk spam dan komentar yang tidak perlu ditindaklanjuti">
                    <x-icon name="ban" class="h-4 w-4"/> Abaikan
                </button>

                <button name="action" value="reclassify" class="btn-outline !py-2"
                        title="Nilai ulang dengan AI">
                    <x-icon name="robot" class="h-4 w-4"/> Nilai ulang
                </button>

                <button type="button" @click="selected = []" class="btn-ghost !py-2 ml-auto">
                    <x-icon name="x" class="h-4 w-4"/> Batal
                </button>
            </div>

            @error('ids') <p class="form-error mb-3">{{ $message }}</p> @enderror
            @error('assigned_to') <p class="form-error mb-3">{{ $message }}</p> @enderror
        @endif

        <div class="card divide-y divide-slate-100 overflow-hidden dark:divide-white/5">
            @forelse ($interactions as $interaction)
                <x-interaction-row :interaction="$interaction" :selectable="$canHandle"/>
            @empty
                @php
                    // An empty tab should say why it is empty and what to do,
                    // not give everyone the same shrug. A supervisor opening
                    // "Tugas Saya" and finding nothing is the normal case —
                    // they hand work out, they do not take it — so point them
                    // at the tab that actually answers their question.
                    [$emptyTitle, $emptyBody] = match (true) {
                        $tab === 'urgent' => [
                            'Tidak ada komentar mendesak',
                            'Bagus — tidak ada yang perlu ditangani segera saat ini.',
                        ],
                        $tab === 'mine' && $canSeeAllTasks => [
                            'Tidak ada tugas atas nama Anda',
                            'Wajar untuk peran pengawas. Buka tab “Tugas Petugas” untuk melihat pekerjaan seluruh tim.',
                        ],
                        $tab === 'mine' => [
                            'Belum ada tugas untuk Anda',
                            'Tugas muncul di sini setelah Manager menugaskan sebuah interaksi kepada Anda.',
                        ],
                        $tab === 'assigned' => [
                            'Belum ada tugas yang dibagikan',
                            'Belum ada interaksi yang ditugaskan ke petugas mana pun. Tugaskan dari tab antrean.',
                        ],
                        $tab === 'done' => [
                            'Belum ada yang selesai',
                            'Interaksi yang sudah dibalas, diselesaikan, atau diabaikan akan tersimpan di sini.',
                        ],
                        default => [
                            'Belum ada interaksi',
                            'Komentar ditarik otomatis setiap 5 jam. Jalankan sinkron atau tunggu jadwal berikutnya.',
                        ],
                    };
                @endphp

                <x-empty-state icon="inbox" :title="$emptyTitle" :description="$emptyBody" class="!py-14">
                    @if ($tab === 'mine' && $canSeeAllTasks)
                        <x-slot:action>
                            <a href="{{ route('interactions.index', ['tab' => 'assigned']) }}" class="btn-primary">
                                <x-icon name="users" class="h-4 w-4"/> Lihat Tugas Petugas
                            </a>
                        </x-slot:action>
                    @endif
                </x-empty-state>
            @endforelse
        </div>
    </form>

    @if ($interactions->hasPages())
        <div class="mt-6">{{ $interactions->links() }}</div>
    @endif
    @endif
</x-layouts.app>
