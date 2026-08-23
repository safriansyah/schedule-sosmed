<x-layouts.app title="Ubah Akun">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">{{ $account->name }}</h1>
            <p class="mt-0.5 text-sm text-slate-400">{{ $account->handle() }} · {{ $account->platform->label() }}</p>
        </div>
        <a href="{{ route('accounts.index') }}" class="btn-outline">
            <x-icon name="chevron-left" class="h-4 w-4"/> Kembali
        </a>
    </x-slot:header>

    <form method="POST" action="{{ route('accounts.update', $account) }}">
        @csrf
        @method('PUT')
        @include('accounts.partials.form')
    </form>
</x-layouts.app>
