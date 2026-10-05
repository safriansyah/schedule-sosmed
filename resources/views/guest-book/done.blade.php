<x-layouts.public title="Nomor Antrian {{ $entry->displayNumber() }}">
    <div class="overflow-hidden rounded-3xl border border-slate-200/80 bg-white text-center shadow-xl shadow-slate-200/50 dark:border-white/10 dark:bg-ink-900 dark:shadow-none">
        <div class="bg-gradient-to-br from-brand-700 via-brand-600 to-accent-500 px-6 pb-10 pt-8 text-white">
            <div class="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-full bg-white/20">
                <x-icon name="check" class="h-6 w-6"/>
            </div>
            <p class="text-sm font-semibold text-white/85">Berhasil! Anda sudah mengambil nomor antrian.</p>

            <p class="mt-5 text-xs font-semibold uppercase tracking-[0.25em] text-white/70">Nomor antrian Anda</p>
            <p class="mt-1 font-mono text-7xl font-extrabold tracking-tight drop-shadow-sm sm:text-8xl">{{ $entry->displayNumber() }}</p>
            <p class="mt-2 text-sm text-white/80">{{ $entry->queue_date->translatedFormat('l, d F Y') }}</p>
        </div>

        <div class="space-y-4 p-6 sm:p-8">
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

            <div class="rounded-2xl border border-brand-500/20 bg-brand-500/[0.05] p-4 text-sm text-slate-600 dark:text-slate-300">
                @if ($entry->status === \App\Enums\GuestBookStatus::Waiting)
                    @if ($ahead > 0)
                        Ada <strong class="text-brand-700 dark:text-brand-300">{{ $ahead }} orang</strong> sebelum Anda.
                    @else
                        Anda <strong class="text-brand-700 dark:text-brand-300">berikutnya</strong> dalam antrian.
                    @endif
                    Silakan menunggu — nomor Anda akan dipanggil dan tampil di layar monitor.
                @else
                    Status antrian Anda: <strong>{{ $entry->status->label() }}</strong>.
                @endif
            </div>

            <div class="grid gap-2 sm:grid-cols-2">
                <a href="{{ route('guest-book.monitor') }}" class="btn-primary w-full">
                    <x-icon name="monitor" class="h-4 w-4"/> Lihat Monitor Antrian
                </a>
                <a href="{{ route('guest-book.create') }}" class="btn-outline w-full">
                    <x-icon name="plus" class="h-4 w-4"/> Isi untuk Tamu Lain
                </a>
            </div>

            <p class="text-xs text-slate-400">Simpan atau foto layar ini sebagai bukti nomor antrian Anda.</p>
        </div>
    </div>
</x-layouts.public>
