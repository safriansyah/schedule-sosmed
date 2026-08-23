<x-layouts.app title="Buat Konten">
    <x-slot:header>
        <div>
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Buat Konten</h1>
            <p class="mt-0.5 text-sm text-slate-400">Draft akan disimpan dulu — kirim ke approval saat sudah siap.</p>
        </div>

        <a href="{{ route('contents.index') }}" class="btn-outline">
            <x-icon name="chevron-left" class="h-4 w-4"/> Kembali
        </a>
    </x-slot:header>

    <form method="POST" action="{{ route('contents.store') }}" enctype="multipart/form-data">
        @csrf

        @include('contents.partials.form', ['content' => null])

        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <a href="{{ route('contents.index') }}" class="btn-outline">Batal</a>
            <button type="submit" class="btn-primary">
                <x-icon name="check" class="h-4 w-4"/> Simpan Draft
            </button>
        </div>
    </form>
</x-layouts.app>
