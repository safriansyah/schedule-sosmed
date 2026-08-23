<x-layouts.app title="Ubah Konten">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Ubah Konten</h1>
            <p class="mt-0.5 flex items-center gap-2 text-sm text-slate-400">
                <x-status-badge :status="$content->status"/>
                <span class="truncate">{{ $content->title }}</span>
            </p>
        </div>

        <a href="{{ route('contents.show', $content) }}" class="btn-outline">
            <x-icon name="chevron-left" class="h-4 w-4"/> Kembali
        </a>
    </x-slot:header>

    <form method="POST" action="{{ route('contents.update', $content) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        @include('contents.partials.form')

        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <a href="{{ route('contents.show', $content) }}" class="btn-outline">Batal</a>
            <button type="submit" class="btn-primary">
                <x-icon name="check" class="h-4 w-4"/> Simpan Perubahan
            </button>
        </div>
    </form>
</x-layouts.app>
