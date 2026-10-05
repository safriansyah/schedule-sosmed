{{-- "Per akun": one row per account, expanded on demand. The rows inside are
     fetched only when opened, so a page of 25 accounts stays one query. --}}
<div class="mb-3 flex flex-wrap items-center justify-between gap-3">
    <h2 class="text-base font-bold text-slate-800 dark:text-white">
        {{ number_format($accounts->total()) }} akun
    </h2>
    <span class="text-xs text-slate-400">Diurutkan: interaksi terbaru dulu</span>
</div>

<div class="space-y-3">
    @forelse ($accounts as $account)
        @php
            $handle = $account->author_handle;
            $isOpen = (int) $account->open_count > 0;
            $lastAt = $account->last_at ? \Illuminate\Support\Carbon::parse($account->last_at) : null;
            $rowsUrl = route('interactions.account', array_filter(['channel' => $account->channel->value, 'handle' => $handle]));
        @endphp

        <div class="card overflow-hidden {{ $account->any_urgent ? '!border-rose-300/60 dark:!border-rose-500/30' : '' }}"
             x-data="{ open: false, loaded: false, loading: false,
                       async toggle() {
                           this.open = ! this.open;
                           if (this.open && ! this.loaded) {
                               this.loading = true;
                               try {
                                   const r = await fetch(@js($rowsUrl), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                                   if (! r.ok) throw new Error();
                                   this.$refs.rows.innerHTML = await r.text();
                                   this.loaded = true;
                               } catch (e) {
                                   window.toast('Gagal memuat interaksi akun ini.', 'error');
                                   this.open = false;
                               } finally { this.loading = false; }
                           }
                       } }">
            <div class="flex flex-wrap items-center gap-3 p-4">
                <button type="button" @click="toggle()" class="flex min-w-0 flex-1 items-center gap-3 text-left">
                    <span class="avatar relative h-11 w-11 shrink-0 overflow-hidden text-sm">
                        {{ $account->initial() }}
                        @if ($account->avatar())
                            <img src="{{ $account->avatar() }}" alt="" loading="lazy" referrerpolicy="no-referrer"
                                 class="absolute inset-0 h-full w-full object-cover" onerror="this.remove()">
                        @endif
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="truncate font-bold text-slate-800 dark:text-white">{{ $account->handle() }}</span>
                            <x-icon :name="$account->channel->value" class="h-3.5 w-3.5 text-slate-400"/>
                            @if ($account->any_urgent)
                                <span class="badge-red"><x-icon name="flame" class="h-3 w-3"/> Mendesak</span>
                            @endif
                        </span>
                        @if ($account->author_name && $account->author_name !== $handle)
                            <span class="block truncate text-xs text-slate-400">{{ $account->author_name }}</span>
                        @endif
                    </span>
                </button>

                <dl class="flex flex-wrap items-center gap-x-5 gap-y-1 text-xs">
                    <div>
                        <dt class="text-slate-400">Total Interactions</dt>
                        <dd class="text-base font-extrabold text-slate-800 dark:text-white">{{ number_format($account->total) }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Last Interaction</dt>
                        <dd class="font-semibold text-slate-700 dark:text-slate-200">{{ $lastAt?->timezone('Asia/Jakarta')->format('d-m-Y H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Status</dt>
                        <dd>
                            @if ($isOpen)
                                <span class="badge-amber">Open · {{ $account->open_count }}</span>
                            @else
                                <span class="badge-slate">Closed</span>
                            @endif
                        </dd>
                    </div>
                </dl>

                <div class="flex items-center gap-2">
                    @if ($canHandle && $isOpen)
                        <form method="POST" action="{{ route('interactions.account.close') }}"
                              onsubmit="return confirm('Tutup semua interaksi terbuka dari {{ $account->handle() }}? Riwayat tetap tersimpan.')">
                            @csrf
                            <input type="hidden" name="channel" value="{{ $account->channel->value }}">
                            <input type="hidden" name="handle" value="{{ $handle }}">
                            <button class="btn-outline btn-sm" title="Close semua interaksi terbuka dari akun ini">
                                <x-icon name="check" class="h-3.5 w-3.5"/> Close semua
                            </button>
                        </form>
                    @endif

                    <button type="button" @click="toggle()" class="btn-ghost btn-sm" :aria-expanded="open">
                        <x-icon name="refresh" class="h-3.5 w-3.5 animate-spin" x-show="loading" x-cloak/>
                        <span x-text="open ? 'Tutup' : 'Lihat semua'"></span>
                        <x-icon name="chevron-down" class="h-3.5 w-3.5 transition" ::class="open && 'rotate-180'"/>
                    </button>
                </div>
            </div>

            <div x-show="open" x-cloak x-collapse.duration.200ms class="border-t border-slate-100 dark:border-white/5">
                <div x-ref="rows" class="divide-y divide-slate-100 dark:divide-white/5">
                    <p class="p-4 text-sm text-slate-400">Memuat…</p>
                </div>
            </div>
        </div>
    @empty
        <div class="card">
            <x-empty-state icon="inbox" title="Tidak ada akun" description="Tidak ada interaksi yang cocok dengan tab dan filter ini."/>
        </div>
    @endforelse
</div>

@if ($accounts->hasPages())
    <div class="mt-6">{{ $accounts->links() }}</div>
@endif
