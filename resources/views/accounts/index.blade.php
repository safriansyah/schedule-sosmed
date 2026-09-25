<x-layouts.app title="Akun Sosmed">
    <x-slot:header>
        <div>
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Akun Sosmed</h1>
            <p class="mt-0.5 text-sm text-slate-400">Akun tujuan publikasi beserta kredensialnya.</p>
        </div>

        @can(App\Enums\Permission::ManageAccounts->value)
            <a href="{{ route('accounts.create') }}" class="btn-primary">
                <x-icon name="plus" class="h-4 w-4"/> Hubungkan Akun
            </a>
        @endcan
    </x-slot:header>

    @php
        // Expired and expiring are different problems with different answers,
        // so they get different banners. Lumping them together told someone
        // whose token lapsed yesterday that it "will expire soon".
        $expired = $accounts->filter->isTokenExpired();

        $expiring = $accounts->reject->isTokenExpired()->filter(fn ($a) => $a->token_expires_at
            && $a->token_expires_at->lt(now()->addDays(\App\Services\Publishing\TokenRefresher::WARN_WITHIN_DAYS)));

        // Only claim automatic renewal happens if the scheduler is actually
        // alive. Promising a refresh that cannot run is how a token lapses
        // while everyone assumes it is handled.
        $schedulerAlive = app(\App\Services\SystemHealth::class)->lastRun()?->gt(now()->subMinutes(10)) ?? false;
    @endphp

    @if ($expired->isNotEmpty())
        <div class="card mb-5 border-rose-500/30 bg-rose-500/[0.06] p-4">
            <div class="flex items-start gap-3">
                <x-icon name="alert" class="mt-0.5 h-5 w-5 shrink-0 text-rose-500"/>
                <div class="min-w-0 text-sm">
                    <p class="font-semibold text-rose-600 dark:text-rose-400">Token sudah kedaluwarsa</p>
                    <p class="mt-0.5 text-slate-600 dark:text-slate-300">
                        {{ $expired->pluck('name')->implode(', ') }} —
                        Instagram tidak bisa memperpanjang token yang sudah lewat.
                        Buat token baru di Meta Developers, lalu tempelkan lewat tombol Ubah pada akun tersebut.
                    </p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Sinkron komentar, insight, dan penerbitan terjadwal berhenti untuk akun ini sampai tokennya diganti.
                    </p>
                </div>
            </div>
        </div>
    @endif

    @if ($expiring->isNotEmpty())
        <div class="card mb-5 flex items-start gap-3 border-amber-200 bg-amber-50/60 p-4 dark:border-amber-500/20 dark:bg-amber-500/5">
            <x-icon name="alert" class="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400"/>
            <div class="text-sm text-amber-800 dark:text-amber-300">
                <p class="font-semibold">Token akan segera kedaluwarsa</p>
                <p class="mt-0.5">
                    {{ $expiring->pluck('name')->implode(', ') }} —
                    @if ($schedulerAlive)
                        perpanjangan otomatis berjalan tiap hari, tetapi jika gagal, perbarui token secara manual.
                    @else
                        <strong>penjadwal sedang tidak berjalan</strong>, jadi perpanjangan otomatis TIDAK akan terjadi.
                        Jalankan <span class="font-mono text-xs">schedule:work</span> di server, atau perbarui token manual.
                    @endif
                </p>
            </div>
        </div>
    @endif

    @if ($accounts->isNotEmpty())
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($accounts as $account)
        <div class="card-glow p-5">
            <div class="flex items-start gap-3">
                <x-account-avatar :account="$account" size="h-12 w-12"/>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-slate-800 dark:text-white">{{ $account->name }}</p>
                    <p class="truncate text-xs text-slate-400">{{ $account->handle() }}</p>
                    <p class="mt-1 flex flex-wrap items-center gap-1.5">
                        <span class="badge-slate">
                            <x-icon :name="$account->platform->icon()" class="h-3 w-3"/>
                            {{ $account->platform->label() }}
                        </span>
                        @if ($account->is_active)
                            <span class="badge-green">Aktif</span>
                        @else
                            <span class="badge-slate">Nonaktif</span>
                        @endif
                        @if ($account->isTokenExpired())
                            <span class="badge-red" title="Kedaluwarsa {{ $account->token_expires_at->translatedFormat('d M Y') }}">
                                <x-icon name="alert" class="h-3 w-3"/> Token kedaluwarsa
                            </span>
                        @elseif ($account->token_expires_at)
                            <span class="{{ $account->token_expires_at->lt(now()->addDays(14)) ? 'badge-amber' : 'badge-slate' }}">
                                <x-icon name="clock" class="h-3 w-3"/>
                                Token {{ $account->token_expires_at->diffForHumans() }}
                            </span>
                        @elseif (data_get($account->meta, 'expiry_unknown_since'))
                            {{-- Verified as working, but the platform would not
                                 tell us how long it has left. Not a problem, so
                                 not a warning colour. --}}
                            <span class="badge-slate" title="{{ data_get($account->meta, 'expiry_note') }}">
                                <x-icon name="help-circle" class="h-3 w-3"/> Masa berlaku tidak diketahui
                            </span>
                        @endif
                        @unless ($account->platform->isImplemented())
                            <span class="badge-amber">Belum didukung</span>
                        @endunless
                    </p>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-3 gap-2 border-t border-slate-100 pt-4 text-center dark:border-white/5">
                <div>
                    <p class="text-base font-bold text-slate-800 dark:text-white">{{ number_format($account->followers_count) }}</p>
                    <p class="text-[11px] text-slate-400">Followers</p>

                    @if ($prev = $previous[$account->id] ?? null)
                        <x-delta :current="$account->followers_count" :previous="$prev->followers"
                                 class="mt-1 !text-[10px]"/>
                    @endif
                </div>
                <div>
                    <p class="text-base font-bold text-slate-800 dark:text-white">{{ number_format($account->media_count) }}</p>
                    <p class="text-[11px] text-slate-400">Media</p>
                </div>
                <div>
                    <p class="text-base font-bold text-slate-800 dark:text-white">{{ number_format($account->schedules_count) }}</p>
                    <p class="text-[11px] text-slate-400">Jadwal</p>
                </div>
            </div>

            @can(App\Enums\Permission::ManageAccounts->value)
                <div class="mt-4 flex gap-2">
                    <form method="POST" action="{{ route('accounts.verify', $account) }}" class="flex-1">
                        @csrf
                        <button class="btn-outline btn-sm w-full">
                            <x-icon name="refresh" class="h-3.5 w-3.5"/> Tes Koneksi
                        </button>
                    </form>

                    <a href="{{ route('accounts.edit', $account) }}" class="btn-outline btn-sm">
                        <x-icon name="edit" class="h-3.5 w-3.5"/>
                    </a>

                    <form method="POST" action="{{ route('accounts.destroy', $account) }}"
                          onsubmit="return confirm('Hapus akun ini? Jadwal yang belum terbit ikut terhapus.')">
                        @csrf
                        @method('DELETE')
                        <button class="btn-danger btn-sm"><x-icon name="trash" class="h-3.5 w-3.5"/></button>
                    </form>
                </div>
            @endcan
        </div>

        @endforeach
    </div>
    @else
        <div class="card">
            <x-empty-state icon="link" title="Belum ada akun terhubung"
                           description="Hubungkan akun Instagram agar konten bisa dipublikasikan.">
                <x-slot:action>
                    @can(App\Enums\Permission::ManageAccounts->value)
                        <a href="{{ route('accounts.create') }}" class="btn-primary">
                            <x-icon name="plus" class="h-4 w-4"/> Hubungkan Akun
                        </a>
                    @endcan
                </x-slot:action>
            </x-empty-state>
        </div>
    @endif
</x-layouts.app>
