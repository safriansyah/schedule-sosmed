<x-layouts.app title="Tambah Pengguna">
    <x-slot:header>
        <div>
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Tambah Pengguna</h1>
            <p class="mt-0.5 text-sm text-slate-400">Buat akun baru dan tentukan hak aksesnya.</p>
        </div>
        <a href="{{ route('users.index') }}" class="btn-outline">
            <x-icon name="chevron-left" class="h-4 w-4"/> Kembali
        </a>
    </x-slot:header>

    <form method="POST" action="{{ route('users.store') }}">
        @csrf
        @include('users.partials.form', ['user' => null])
    </form>
</x-layouts.app>
