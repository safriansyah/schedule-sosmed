<x-layouts.app title="Aktivitas">
    <x-slot:header>
        <div>
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Log Aktivitas</h1>
            <p class="mt-0.5 text-sm text-slate-400">Jejak audit seluruh perubahan di sistem.</p>
        </div>
    </x-slot:header>

    {{-- Filters --}}
    <form method="GET" class="card mb-6 flex flex-col gap-3 p-4 sm:flex-row sm:items-end">
        <div class="sm:w-56">
            <label for="user" class="label">Pengguna</label>
            <select id="user" name="user" class="input">
                <option value="">Semua</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}" @selected(($filters['user'] ?? null) == $user->id)>{{ $user->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="sm:w-56">
            <label for="action" class="label">Jenis aksi</label>
            <select id="action" name="action" class="input">
                <option value="">Semua</option>
                @foreach (['content' => 'Konten', 'auth' => 'Autentikasi'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['action'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex gap-2">
            <button class="btn-primary"><x-icon name="filter" class="h-4 w-4"/> Filter</button>
            @if (array_filter($filters))
                <a href="{{ route('activities.index') }}" class="btn-outline"><x-icon name="x" class="h-4 w-4"/></a>
            @endif
        </div>
    </form>

    {{-- Timeline --}}
    <div class="card p-5">
        @forelse ($activities as $activity)
            <div class="flex gap-3 border-l-2 border-slate-100 pb-5 pl-4 last:pb-0 dark:border-white/5">
                <div class="-ml-[27px] mt-0.5">
                    <span class="badge-blue !h-7 !w-7 !justify-center !rounded-full !p-0">
                        <x-icon :name="$activity->icon()" class="h-3.5 w-3.5"/>
                    </span>
                </div>

                <div class="min-w-0 flex-1">
                    <p class="text-sm text-slate-700 dark:text-slate-200">{{ $activity->description }}</p>
                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-slate-400">
                        <span class="font-medium">{{ $activity->user?->name ?? 'Sistem' }}</span>
                        <span>·</span>
                        <span>{{ $activity->created_at->translatedFormat('d M Y, H:i') }}</span>
                        <span>·</span>
                        <code class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] dark:bg-white/5">{{ $activity->action }}</code>
                    </p>
                </div>
            </div>
        @empty
            <x-empty-state icon="activity" title="Belum ada aktivitas"
                           description="Setiap perubahan akan tercatat otomatis di sini."/>
        @endforelse
    </div>

    @if ($activities->hasPages())
        <div class="mt-6">{{ $activities->links() }}</div>
    @endif
</x-layouts.app>
