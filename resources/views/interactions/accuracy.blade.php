<x-layouts.app title="Akurasi Klasifikasi">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Akurasi Klasifikasi</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Dihitung dari koreksi manual petugas — satu-satunya ukuran jujur yang kita punya.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <form method="GET">
                <select name="days" class="input !w-auto !py-2" onchange="this.form.submit()">
                    @foreach ([7 => '7 hari', 30 => '30 hari', 90 => '90 hari', 365 => '1 tahun'] as $value => $label)
                        <option value="{{ $value }}" @selected($days === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </form>

            <a href="{{ route('interactions.index') }}" class="btn-outline">
                <x-icon name="chevron-left" class="h-4 w-4"/> Kembali
            </a>
        </div>
    </x-slot:header>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-stat-card label="Perkiraan akurasi"
                     :value="$accuracy['rate'] !== null ? $accuracy['rate'].'%' : '—'"
                     icon="target"
                     :tone="($accuracy['rate'] ?? 100) >= 85 ? 'emerald' : 'amber'"
                     hint="Yang tidak dikoreksi dianggap benar"/>

        <x-stat-card label="Dinilai AI" :value="number_format($accuracy['classified'])"
                     icon="robot" tone="brand" hint="Dalam periode ini"/>

        <x-stat-card label="Dikoreksi manusia" :value="number_format($accuracy['corrections'])"
                     icon="edit" tone="violet" hint="Sentimen diubah petugas"/>
    </div>

    {{-- The honest caveat, stated where the number is, not in a footnote. --}}
    <div class="card mb-6 flex items-start gap-3 border-amber-500/30 bg-amber-500/[0.05] p-4">
        <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400"/>
        <div class="text-sm text-amber-800 dark:text-amber-300">
            <p class="font-semibold">Baca angka ini dengan hati-hati.</p>
            <p class="mt-1 text-amber-700 dark:text-amber-400/90">
                Angka akurasi mengasumsikan setiap penilaian yang <em>tidak</em> dikoreksi berarti benar.
                Padahal komentar yang tidak pernah dibuka petugas juga tidak pernah dikoreksi. Jadi ini
                batas <strong>atas</strong>, bukan akurasi sebenarnya — gunanya untuk melihat
                <em>tren</em> dan <em>pola kesalahan</em>, bukan untuk dilaporkan sebagai capaian.
            </p>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Which mistake, how often --}}
        <div class="card p-5">
            <h2 class="mb-4 text-sm font-bold text-slate-800 dark:text-white">Pola Kesalahan</h2>

            @forelse ($accuracy['pairs'] as $pair)
                @php
                    $ai = App\Enums\Sentiment::tryFrom($pair->ai);
                    $human = App\Enums\Sentiment::tryFrom($pair->human);
                @endphp
                <div class="mb-2 flex items-center gap-2 rounded-xl bg-slate-50 p-3 dark:bg-white/5">
                    <span class="{{ $ai?->badge() ?? 'badge-slate' }}">{{ $ai?->label() ?? $pair->ai }}</span>
                    <x-icon name="chevron-right" class="h-3 w-3 shrink-0 text-slate-400"/>
                    <span class="{{ $human?->badge() ?? 'badge-slate' }}">{{ $human?->label() ?? $pair->human }}</span>
                    <span class="ml-auto text-sm font-bold text-slate-700 dark:text-slate-200">{{ $pair->total }}×</span>
                </div>
            @empty
                <x-empty-state icon="check-circle" title="Belum ada koreksi"
                               description="Belum ada petugas yang tidak setuju dengan penilaian AI dalam periode ini."
                               class="!py-8"/>
            @endforelse

            @if ($accuracy['pairs']->isNotEmpty())
                <p class="mt-4 border-t border-slate-100 pt-3 text-xs text-slate-400 dark:border-white/5">
                    Pola yang berulang biasanya bisa diperbaiki dengan menambah kata ke
                    <code class="text-[11px]">Lexicon.php</code> atau menyesuaikan prompt.
                </p>
            @endif
        </div>

        {{-- The corrections themselves --}}
        <div class="card overflow-hidden lg:col-span-2">
            <div class="p-5 pb-3">
                <h2 class="text-sm font-bold text-slate-800 dark:text-white">
                    Koreksi Terakhir
                    <span class="badge-slate ml-1">{{ number_format($corrections->total()) }}</span>
                </h2>
            </div>

            <div class="divide-y divide-slate-100 dark:divide-white/5">
                @forelse ($corrections as $interaction)
                    <a href="{{ route('interactions.show', $interaction) }}" class="row-hover block px-5 py-3">
                        <p class="line-clamp-2 text-sm text-slate-600 dark:text-slate-300">
                            {{ $interaction->shortText(140) }}
                        </p>

                        <p class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                            <span class="{{ $interaction->sentiment?->badge() ?? 'badge-slate' }}">
                                AI: {{ $interaction->sentiment?->label() ?? '—' }}
                            </span>
                            <x-icon name="chevron-right" class="h-3 w-3 text-slate-400"/>
                            <span class="{{ $interaction->sentiment_override?->badge() ?? 'badge-slate' }}">
                                {{ $interaction->overrider?->name ?? 'Petugas' }}: {{ $interaction->sentiment_override?->label() }}
                            </span>
                            @if ($interaction->ai_model)
                                <span class="text-slate-400">· {{ $interaction->ai_model }} ({{ $interaction->ai_confidence }}%)</span>
                            @endif
                            <span class="text-slate-400">· {{ $interaction->override_at?->diffForHumans() }}</span>
                        </p>
                    </a>
                @empty
                    <x-empty-state icon="edit" title="Belum ada koreksi tercatat"
                                   description="Saat petugas mengoreksi sentimen di halaman detail, koreksinya muncul di sini."
                                   class="!py-12"/>
                @endforelse
            </div>

            @if ($corrections->hasPages())
                <div class="p-5">{{ $corrections->links() }}</div>
            @endif
        </div>
    </div>
</x-layouts.app>
