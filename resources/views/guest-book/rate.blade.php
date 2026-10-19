<x-layouts.public title="Penilaian Layanan">
    @php
        $labels = [1 => 'Sangat buruk', 2 => 'Buruk', 3 => 'Cukup', 4 => 'Baik', 5 => 'Sangat baik'];
    @endphp

    <div class="overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-xl shadow-slate-200/50 dark:border-white/10 dark:bg-ink-900 dark:shadow-none">
        <div class="bg-gradient-to-br from-brand-700 via-brand-600 to-accent-500 px-6 pb-8 pt-7 text-center text-white">
            <div class="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-full bg-white/20">
                <x-icon name="star" class="h-6 w-6"/>
            </div>
            <p class="text-lg font-bold">Penilaian Layanan</p>
            <p class="mt-1 text-sm text-white/80">
                Antrian <span class="font-mono font-bold">{{ $entry->displayNumber() }}</span>
                · {{ $entry->queue_date->translatedFormat('d F Y') }}
            </p>
        </div>

        <div class="space-y-5 p-6 sm:p-8">
            <dl class="grid gap-3 text-left text-sm sm:grid-cols-2">
                <div class="rounded-2xl bg-slate-50 p-3 dark:bg-white/5">
                    <dt class="text-xs text-slate-400">Nama</dt>
                    <dd class="font-semibold text-slate-800 dark:text-white">{{ $entry->name }}</dd>
                </div>
                <div class="rounded-2xl bg-slate-50 p-3 dark:bg-white/5">
                    <dt class="text-xs text-slate-400">Layanan</dt>
                    <dd class="font-semibold text-slate-800 dark:text-white">{{ $entry->serviceLabel() }}</dd>
                </div>
            </dl>

            @if (session('success'))
                <div class="flex items-center gap-2 rounded-2xl border border-emerald-500/30 bg-emerald-500/[0.07] p-3 text-sm font-semibold text-emerald-700 dark:text-emerald-300">
                    <x-icon name="check-circle" class="h-5 w-5 shrink-0"/> {{ session('success') }}
                </div>
            @endif

            @if ($entry->hasRating())
                {{-- Given once; shown back, not editable. --}}
                <div class="text-center">
                    <p class="text-sm text-slate-500 dark:text-slate-400">Penilaian Anda</p>
                    <div class="mt-2 flex justify-center gap-1" aria-label="{{ $entry->rating }} dari 5 bintang">
                        @for ($i = 1; $i <= 5; $i++)
                            <svg class="h-9 w-9 {{ $i <= $entry->rating ? 'text-amber-400' : 'text-slate-200 dark:text-white/10' }}" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                            </svg>
                        @endfor
                    </div>
                    <p class="mt-1 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $labels[$entry->rating] ?? '' }}</p>

                    @if ($entry->review)
                        <blockquote class="mt-4 whitespace-pre-line rounded-2xl bg-slate-50 p-4 text-left text-sm text-slate-600 dark:bg-white/5 dark:text-slate-300">“{{ $entry->review }}”</blockquote>
                    @endif

                    <p class="mt-4 text-xs text-slate-400">Terima kasih — masukan Anda membantu kami melayani lebih baik.</p>
                </div>
            @elseif (! $entry->isServed())
                <div class="rounded-2xl border border-amber-500/30 bg-amber-500/[0.07] p-4 text-sm text-slate-600 dark:text-slate-300">
                    <p class="font-semibold text-amber-700 dark:text-amber-300">Layanan Anda belum selesai</p>
                    <p class="mt-1">
                        Status saat ini: <strong>{{ $entry->ticket && $entry->status === \App\Enums\GuestBookStatus::Ticketed ? 'Diproses sebagai tiket '.$entry->ticket->number : $entry->status->label() }}</strong>.
                        Penilaian bisa diberikan setelah layanan selesai — buka kembali tautan ini nanti.
                    </p>
                </div>
            @else
                <form method="POST" action="{{ $action }}" class="space-y-5"
                      x-data="{ rating: {{ (int) old('rating', 0) }}, hover: 0, labels: @js($labels) }">
                    @csrf

                    <div class="text-center">
                        <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">Bagaimana layanan yang Anda terima?</p>

                        <div class="mt-3 flex justify-center gap-1" role="radiogroup" aria-label="Jumlah bintang" @mouseleave="hover = 0">
                            @for ($i = 1; $i <= 5; $i++)
                                <button type="button" role="radio" :aria-checked="rating === {{ $i }}"
                                        aria-label="{{ $i }} bintang — {{ $labels[$i] }}"
                                        @click="rating = {{ $i }}" @mouseenter="hover = {{ $i }}"
                                        class="rounded-lg p-1 transition hover:scale-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                                    <svg class="h-10 w-10 transition sm:h-11 sm:w-11" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"
                                         :class="(hover || rating) >= {{ $i }} ? 'text-amber-400' : 'text-slate-200 dark:text-white/10'">
                                        <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                                    </svg>
                                </button>
                            @endfor
                        </div>

                        <p class="mt-1 h-5 text-sm font-semibold text-slate-600 dark:text-slate-300" x-text="labels[hover || rating] || 'Ketuk bintang untuk menilai'"></p>
                        <input type="hidden" name="rating" :value="rating || ''">
                        @error('rating') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="review" class="label">Ulasan <span class="text-xs font-normal text-slate-400">(opsional)</span></label>
                        <textarea id="review" name="review" rows="4" maxlength="1000" class="input"
                                  placeholder="Ceritakan pengalaman Anda: kecepatan, keramahan petugas, kejelasan informasi…">{{ old('review') }}</textarea>
                        @error('review') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <button class="btn-primary w-full" :disabled="! rating">
                        <x-icon name="send" class="h-4 w-4"/> Kirim Penilaian
                    </button>
                    <p class="text-center text-xs text-slate-400">Penilaian hanya bisa dikirim sekali.</p>
                </form>
            @endif
        </div>
    </div>
</x-layouts.public>
