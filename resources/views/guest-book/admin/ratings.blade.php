<x-layouts.app title="Penilaian Layanan">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Penilaian Layanan</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Bintang &amp; ulasan dari tamu Buku Tamu setelah layanannya selesai. Terbaru di atas.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('guest-book.admin.index') }}" class="btn-outline">
                <x-icon name="id-card" class="h-4 w-4"/> Antrian
            </a>
            <a href="{{ route('guest-book.ratings.export', array_filter($filters)) }}" class="btn-primary">
                <x-icon name="download" class="h-4 w-4"/> Download XLSX
            </a>
        </div>
    </x-slot:header>

    @php
        $labels = \App\Http\Controllers\GuestBookRatingController::LABELS;
        $star = '<path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>';
    @endphp

    {{-- Filters --}}
    <form method="GET" class="card mb-6 p-4">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-12 lg:items-end">
            <div class="col-span-2 lg:col-span-3">
                <label for="q" class="label">Cari nama, NIM, atau ulasan</label>
                <input id="q" name="q" value="{{ $filters['q'] }}" class="input" placeholder="Kata kunci…">
            </div>
            <div class="lg:col-span-2">
                <label for="from" class="label">Dari tanggal</label>
                <input id="from" name="from" type="date" value="{{ $filters['from'] }}" class="input">
            </div>
            <div class="lg:col-span-2">
                <label for="to" class="label">Sampai tanggal</label>
                <input id="to" name="to" type="date" value="{{ $filters['to'] }}" class="input">
            </div>
            <div class="lg:col-span-2">
                <label for="rating" class="label">Bintang</label>
                <select id="rating" name="rating" class="input">
                    <option value="">Semua</option>
                    @foreach ([5, 4, 3, 2, 1] as $n)
                        <option value="{{ $n }}" @selected($filters['rating'] === $n)>{{ $n }} bintang — {{ $labels[$n] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="lg:col-span-1">
                <label for="type" class="label">Jenis</label>
                <select id="type" name="type" class="input">
                    <option value="">Semua</option>
                    @foreach (\App\Enums\GuestBookService::types() as $value => $label)
                        <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-span-2 flex gap-2 lg:col-span-2">
                <button class="btn-primary flex-1"><x-icon name="filter" class="h-4 w-4"/> Terapkan</button>
                <a href="{{ route('guest-book.ratings.index') }}" class="btn-outline" aria-label="Reset filter"><x-icon name="x" class="h-4 w-4"/></a>
            </div>
        </div>
    </form>

    {{-- Summary --}}
    <div class="mb-6 grid gap-4 lg:grid-cols-3">
        <div class="grid grid-cols-3 gap-3 sm:gap-4 lg:col-span-2">
            <x-stat-card label="Total penilaian" :value="number_format($total)" icon="message" tone="brand"/>
            <x-stat-card label="Rata-rata" icon="star" tone="amber"
                         :value="$average !== null ? number_format($average, 1, ',', '.').' / 5' : '—'"/>
            <x-stat-card label="Puas (4–5 ★)" icon="thumbs-up" tone="emerald"
                         :value="$total ? round($satisfied / $total * 100).'%' : '—'"
                         :hint="$total ? number_format($satisfied).' dari '.number_format($total) : null"/>
        </div>

        <div class="card p-4">
            <p class="mb-2 text-xs font-semibold text-slate-500 dark:text-slate-400">Sebaran bintang</p>
            <div class="space-y-1.5">
                @foreach ([5, 4, 3, 2, 1] as $n)
                    @php $count = (int) ($distribution[$n] ?? 0); @endphp
                    <a href="{{ route('guest-book.ratings.index', array_filter(['rating' => $n] + $filters)) }}"
                       class="flex items-center gap-2 rounded-lg px-1 text-xs transition hover:bg-slate-50 dark:hover:bg-white/5">
                        <span class="w-8 shrink-0 font-semibold text-slate-600 dark:text-slate-300">{{ $n }} ★</span>
                        <span class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-white/5">
                            <span class="block h-full rounded-full bg-amber-400" style="width: {{ $total ? round($count / $total * 100) : 0 }}%"></span>
                        </span>
                        <span class="w-8 shrink-0 text-right tabular-nums text-slate-400">{{ number_format($count) }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    </div>

    {{-- The ratings --}}
    <div x-data="{ editing: null, form: { rating: 0, review: '' }, hover: 0, labels: @js($labels) }"
         @keydown.escape.window="editing = null">
        @forelse ($entries as $entry)
            <div class="card mb-3 p-4 sm:p-5">
                <div class="flex flex-wrap items-start gap-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center gap-0.5" aria-label="{{ $entry->rating }} dari 5 bintang">
                                @for ($i = 1; $i <= 5; $i++)
                                    <svg class="h-5 w-5 {{ $i <= $entry->rating ? 'text-amber-400' : 'text-slate-200 dark:text-white/10' }}" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">{!! $star !!}</svg>
                                @endfor
                            </span>
                            <span class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ $labels[$entry->rating] ?? '' }}</span>
                        </div>

                        @if ($entry->review)
                            <p class="mt-2 whitespace-pre-line break-words text-sm text-slate-700 dark:text-slate-200">“{{ $entry->review }}”</p>
                        @else
                            <p class="mt-2 text-sm italic text-slate-400">Tanpa ulasan.</p>
                        @endif

                        <p class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                            <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $entry->name }}</span>
                            @if ($entry->nim) <span class="font-mono">{{ $entry->nim }}</span> @endif
                            <span>Antrian <span class="font-mono font-semibold">{{ $entry->displayNumber() }}</span> · {{ $entry->queue_date->translatedFormat('d M Y') }}</span>
                            <span class="{{ $entry->service->type() === \App\Enums\GuestBookService::TYPE_COMPLAINT ? 'badge-red' : 'badge-blue' }}">{{ $entry->service->label() }}</span>
                            @if ($entry->completer) <span>Petugas: {{ $entry->completer->name }}</span> @endif
                        </p>
                        <p class="mt-1 text-[11px] text-slate-400">Dinilai {{ $entry->rated_at?->translatedFormat('d M Y, H:i') }}</p>
                    </div>

                    {{-- Only the admin may correct or remove a rating. --}}
                    @if ($canManage)
                        <div class="flex shrink-0 gap-2">
                            <button type="button" class="btn-outline btn-sm"
                                    @click="editing = @js(route('guest-book.ratings.update', $entry)); form = { rating: {{ $entry->rating }}, review: @js((string) $entry->review) }; hover = 0">
                                <x-icon name="edit" class="h-3.5 w-3.5"/> Ubah
                            </button>
                            <form method="POST" action="{{ route('guest-book.ratings.destroy', $entry) }}"
                                  onsubmit="return confirm('Hapus penilaian dari {{ e($entry->name) }}? Kunjungannya tetap tersimpan.')">
                                @csrf
                                @method('DELETE')
                                <button class="btn-outline btn-sm !text-rose-600 dark:!text-rose-400">
                                    <x-icon name="trash" class="h-3.5 w-3.5"/> Hapus
                                </button>
                            </form>
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <div class="card">
                <x-empty-state icon="star" title="Belum ada penilaian"
                               description="Penilaian muncul di sini setelah tamu memberi bintang pada layanan yang sudah selesai."/>
            </div>
        @endforelse

        <div class="mt-4">{{ $entries->links() }}</div>

        {{-- Edit (admin only) --}}
        @if ($canManage)
            <div x-show="editing" x-cloak class="fixed inset-0 z-[90] flex items-center justify-center p-4" role="dialog" aria-modal="true">
                <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" @click="editing = null"></div>

                <form method="POST" :action="editing" class="card relative w-full max-w-md p-6 shadow-2xl" x-show="editing" x-transition>
                    @csrf
                    @method('PUT')

                    <p class="mb-4 text-base font-bold text-slate-800 dark:text-white">Ubah Penilaian</p>

                    <div class="text-center" @mouseleave="hover = 0">
                        <div class="flex justify-center gap-1">
                            @for ($i = 1; $i <= 5; $i++)
                                <button type="button" @click="form.rating = {{ $i }}" @mouseenter="hover = {{ $i }}"
                                        aria-label="{{ $i }} bintang" class="rounded-lg p-1 transition hover:scale-110">
                                    <svg class="h-9 w-9" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"
                                         :class="(hover || form.rating) >= {{ $i }} ? 'text-amber-400' : 'text-slate-200 dark:text-white/10'">{!! $star !!}</svg>
                                </button>
                            @endfor
                        </div>
                        <p class="mt-1 text-sm font-semibold text-slate-600 dark:text-slate-300" x-text="labels[hover || form.rating] || ''"></p>
                        <input type="hidden" name="rating" :value="form.rating">
                    </div>

                    <div class="mt-4">
                        <label for="edit-review" class="label">Ulasan</label>
                        <textarea id="edit-review" name="review" rows="4" maxlength="1000" class="input" x-model="form.review"></textarea>
                    </div>

                    <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button type="button" class="btn-outline" @click="editing = null">Batal</button>
                        <button class="btn-primary" :disabled="! form.rating"><x-icon name="check" class="h-4 w-4"/> Simpan</button>
                    </div>
                </form>
            </div>
        @endif
    </div>
</x-layouts.app>
