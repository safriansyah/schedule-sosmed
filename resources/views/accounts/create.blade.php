<x-layouts.app title="Hubungkan Akun">
    <x-slot:header>
        <div>
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Hubungkan Akun</h1>
            <p class="mt-0.5 text-sm text-slate-400">Koneksi akan langsung diuji setelah disimpan.</p>
        </div>
        <a href="{{ route('accounts.index') }}" class="btn-outline">
            <x-icon name="chevron-left" class="h-4 w-4"/> Kembali
        </a>
    </x-slot:header>

    <form method="POST" action="{{ route('accounts.store') }}">
        @csrf
        @include('accounts.partials.form', ['account' => null])
    </form>
</x-layouts.app>
