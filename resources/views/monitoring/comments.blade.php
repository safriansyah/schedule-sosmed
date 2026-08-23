<x-layouts.app title="Semua Komentar">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Semua Komentar</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Seluruh komentar publik yang tercatat dari postingan akun ini.
            </p>
        </div>

        <a href="{{ route('monitoring.index', ['account' => $account?->id]) }}" class="btn-outline">
            <x-icon name="chevron-left" class="h-4 w-4"/> Kembali
        </a>
    </x-slot:header>

    @if (! $account)
        <div class="card">
            <x-empty-state icon="link" title="Belum ada akun terhubung"
                           description="Hubungkan akun sosmed dulu agar komentarnya bisa dipantau."/>
        </div>
    @else

    {{-- Account + search --}}
    <form method="GET" class="card mb-6 flex flex-col gap-3 p-4 lg:flex-row lg:items-end">
        @if ($accounts->count() > 1)
            <div class="lg:w-56">
                <label for="account" class="label">Akun</label>
                <select id="account" name="account" class="input" onchange="this.form.submit()">
                    @foreach ($accounts as $option)
                        <option value="{{ $option->id }}" @selected($option->id === $account->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <div class="flex-1">
            <label for="q" class="label">Cari komentar / nama</label>
            <div class="relative">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"/>
                <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="input pl-9" placeholder="Kata kunci…">
            </div>
        </div>

        <div class="flex gap-2">
            <button class="btn-primary"><x-icon name="search" class="h-4 w-4"/> Cari</button>
            @if ($filters['q'] ?? null)
                <a href="{{ route('monitoring.comments', ['account' => $account->id]) }}" class="btn-outline">
                    <x-icon name="x" class="h-4 w-4"/>
                </a>
            @endif
        </div>
    </form>

    <div class="mb-4 flex items-center justify-between">
        <h2 class="text-base font-bold text-slate-800 dark:text-white">
            {{ number_format($comments->total()) }} komentar
        </h2>
        <span class="text-xs text-slate-400">Klik postingan untuk lihat detail</span>
    </div>

    <div class="card divide-y divide-slate-100 p-2 dark:divide-white/5">
        @forelse ($comments as $comment)
            <div class="px-2">
                <x-comment-item :comment="$comment" :show-post="true"/>
            </div>
        @empty
            <x-empty-state icon="message" title="Belum ada komentar"
                           description="Komentar publik ditarik otomatis setiap 5 jam. Jalankan sinkron atau tunggu jadwal berikutnya."
                           class="!py-10"/>
        @endforelse
    </div>

    @if ($comments->hasPages())
        <div class="mt-6">{{ $comments->links() }}</div>
    @endif

    @endif
</x-layouts.app>
