@props(['lastSyncedAt' => null])

{{-- Manual "sync now" button with an inline loading state. --}}
<form method="POST" action="{{ route('sync.now') }}"
      x-data="{ loading: false }" @submit="loading = true"
      {{ $attributes->merge(['class' => 'flex items-center gap-2']) }}>
    @csrf

    @if ($lastSyncedAt)
        <span class="hidden text-xs text-slate-400 sm:inline">
            Sinkron {{ $lastSyncedAt->diffForHumans() }}
        </span>
    @endif

    <button type="submit" class="btn-outline" :disabled="loading">
        <span :class="loading && 'animate-spin'" class="inline-flex">
            <x-icon name="refresh" class="h-4 w-4"/>
        </span>
        <span x-text="loading ? 'Menyinkron…' : 'Sinkron Sekarang'"></span>
    </button>
</form>
