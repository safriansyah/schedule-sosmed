<x-layouts.app title="Ubah Pengguna">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">{{ $user->name }}</h1>
            <p class="mt-0.5 truncate text-sm text-slate-400">{{ $user->email }} · {{ $user->roleLabel() }}</p>
        </div>
        <a href="{{ route('users.index') }}" class="btn-outline">
            <x-icon name="chevron-left" class="h-4 w-4"/> Kembali
        </a>
    </x-slot:header>

    <form method="POST" action="{{ route('users.update', $user) }}">
        @csrf
        @method('PUT')
        @include('users.partials.form')
    </form>
</x-layouts.app>
