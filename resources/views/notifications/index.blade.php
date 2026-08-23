<x-layouts.app title="Notifikasi">
    <x-slot:header>
        <div>
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Notifikasi</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                {{ $unread > 0 ? $unread.' belum dibaca' : 'Semua sudah dibaca' }}
            </p>
        </div>

        @if ($unread > 0)
            <form method="POST" action="{{ route('notifications.readAll') }}">
                @csrf
                <button class="btn-outline">
                    <x-icon name="check" class="h-4 w-4"/> Tandai semua terbaca
                </button>
            </form>
        @endif
    </x-slot:header>

    <div class="card divide-y divide-slate-100 dark:divide-white/5">
        @forelse ($notifications as $notification)
            @php
                $data = $notification->data;
                $tones = [
                    'brand' => 'badge-blue', 'emerald' => 'badge-green', 'amber' => 'badge-amber',
                    'rose' => 'badge-red', 'pink' => 'badge-pink', 'cyan' => 'badge-cyan',
                ];
            @endphp

            <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                @csrf
                <button type="submit"
                        class="row-hover flex w-full items-start gap-3 p-4 text-left
                               {{ $notification->read_at ? '' : 'bg-brand-50/40 dark:bg-brand-500/[0.04]' }}">

                    <span class="{{ $tones[$data['tone'] ?? 'brand'] ?? 'badge-blue' }} !h-9 !w-9 shrink-0 !justify-center !rounded-full !p-0">
                        <x-icon :name="$data['icon'] ?? 'bell'" class="h-4 w-4"/>
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-2">
                            <span class="truncate text-sm font-semibold text-slate-800 dark:text-white">
                                {{ $data['title'] ?? 'Notifikasi' }}
                            </span>
                            @unless ($notification->read_at)
                                <span class="h-2 w-2 shrink-0 rounded-full bg-brand-500"></span>
                            @endunless
                        </span>

                        <span class="mt-0.5 block text-sm text-slate-500 dark:text-slate-400">
                            {{ $data['body'] ?? '' }}
                        </span>

                        <span class="mt-1 block text-xs text-slate-400">
                            {{ $notification->created_at->diffForHumans() }}
                        </span>
                    </span>

                    <x-icon name="chevron-right" class="mt-1 h-4 w-4 shrink-0 text-slate-300"/>
                </button>
            </form>
        @empty
            <x-empty-state icon="bell" title="Belum ada notifikasi"
                           description="Pemberitahuan alur kerja akan muncul di sini."/>
        @endforelse
    </div>

    @if ($notifications->hasPages())
        <div class="mt-6">{{ $notifications->links() }}</div>
    @endif
</x-layouts.app>
