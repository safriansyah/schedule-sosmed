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
        $expiring = $accounts->filter(fn ($a) => $a->token_expires_at
            && $a->token_expires_at->lt(now()->addDays(\App\Services\Publishing\TokenRefresher::WARN_WITHIN_DAYS)));
    @endphp

    @if ($expiring->isNotEmpty())
        <div class="card mb-5 flex items-start gap-3 border-amber-200 bg-amber-50/60 p-4 dark:border-amber-500/20 dark:bg-amber-500/5">
            <x-icon name="alert" class="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400"/>
            <div class="text-sm text-amber-800 dark:text-amber-300">
                <p class="font-semibold">Token akan segera kedaluwarsa</p>
                <p class="mt-0.5">
                    {{ $expiring->pluck('name')->implode(', ') }} —
                    perpanjangan otomatis berjalan tiap hari, tetapi jika gagal, perbarui token secara manual.
                </p>
            </div>
        </div>
    @endif

    @if ($accounts->isNotEmpty())
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($accounts as $account)
        <div class="card-glow p-5">
            <div class="flex items-start gap-3">
                @if ($account->avatar_url)
                    <img src="{{ $account->avatar_url }}" alt=""
                         class="h-12 w-12 shrink-0 rounded-full object-cover ring-2 ring-brand-500/40">
                @else
                    <span class="grid h-12 w-12 shrink-0 place-items-center rounded-full text-white"
                          style="background: {{ $account->platform->color() }}">
                        <x-icon :name="$account->platform->icon()" class="h-5 w-5"/>
                    </span>
                @endif

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
                        @if ($account->token_expires_at)
                            <span class="{{ $account->token_expires_at->lt(now()->addDays(14)) ? 'badge-amber' : 'badge-slate' }}">
                                <x-icon name="clock" class="h-3 w-3"/>
                                Token {{ $account->token_expires_at->diffForHumans() }}
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
