<x-layouts.app title="Pengguna">
    <x-slot:header>
        <div>
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Pengguna</h1>
            <p class="mt-0.5 text-sm text-slate-400">{{ $users->total() }} akun terdaftar.</p>
        </div>

        @can(App\Enums\Permission::ManageUsers->value)
            <a href="{{ route('users.create') }}" class="btn-primary">
                <x-icon name="plus" class="h-4 w-4"/> Tambah Pengguna
            </a>
        @endcan
    </x-slot:header>

    {{-- Filters --}}
    <form method="GET" class="card mb-6 flex flex-col gap-3 p-4 sm:flex-row sm:items-end">
        <div class="flex-1">
            <label for="q" class="label">Cari</label>
            <div class="relative">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"/>
                <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="input pl-9" placeholder="Nama atau email…">
            </div>
        </div>

        <div class="sm:w-56">
            <label for="role" class="label">Role</label>
            <select id="role" name="role" class="input">
                <option value="">Semua</option>
                @foreach ($roles as $role)
                    <option value="{{ $role->id }}" @selected(($filters['role'] ?? null) == $role->id)>{{ $role->label }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex gap-2">
            <button class="btn-primary"><x-icon name="filter" class="h-4 w-4"/> Filter</button>
            @if (array_filter($filters))
                <a href="{{ route('users.index') }}" class="btn-outline"><x-icon name="x" class="h-4 w-4"/></a>
            @endif
        </div>
    </form>

    {{-- List --}}
    @if ($users->isNotEmpty())
        {{-- Phone: one card per person. The table is 640px wide at minimum and
             the role labels have since grown ("Manager (Penanganan &
             Pembagian)"), so on a 375px screen it was pure sideways scrolling. --}}
        <div class="space-y-3 md:hidden">
            @foreach ($users as $user)
                <div class="card p-4">
                    <div class="flex items-start gap-3">
                        <span class="avatar h-10 w-10 shrink-0 text-sm">{{ $user->initial() }}</span>

                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold text-slate-800 dark:text-white">{{ $user->name }}</p>
                            <p class="truncate text-xs text-slate-400">{{ $user->email }}</p>
                        </div>

                        @if ($user->is_active)
                            <span class="badge-green shrink-0">Aktif</span>
                        @else
                            <span class="badge-red shrink-0"><x-icon name="ban" class="h-3 w-3"/> Disable</span>
                        @endif
                    </div>

                    <p class="mt-3 flex flex-wrap gap-1.5">
                        <span class="badge-blue">
                            <x-icon :name="$user->role?->name?->icon() ?? 'users'" class="h-3 w-3"/>
                            {{ $user->roleLabel() }}
                        </span>
                        @if ($schedule = $user->loginScheduleLabel())
                            <span class="badge-amber" title="Jadwal login"><x-icon name="clock" class="h-3 w-3"/> {{ $schedule }}</span>
                        @endif
                    </p>

                    <div class="mt-3 flex items-center justify-between gap-3 border-t border-slate-100 pt-3 dark:border-white/5">
                        <span class="text-xs text-slate-400">
                            {{ $user->last_login_at?->translatedFormat('d M Y, H:i') ?? 'Belum pernah login' }}
                        </span>

                        @can(App\Enums\Permission::ManageUsers->value)
                            <span class="flex shrink-0 gap-2">
                                <a href="{{ route('users.edit', $user) }}" class="btn-outline btn-sm">
                                    <x-icon name="edit" class="h-3.5 w-3.5"/>
                                </a>

                                @unless ($user->is(auth()->user()))
                                    <form method="POST" action="{{ route('users.toggle-active', $user) }}"
                                          onsubmit="return confirm('{{ $user->is_active ? 'Disable' : 'Aktifkan kembali' }} akun {{ $user->name }}?')">
                                        @csrf
                                        <button class="{{ $user->is_active ? 'btn-outline' : 'btn-success' }} btn-sm" title="{{ $user->is_active ? 'Disable — tidak bisa login' : 'Aktifkan kembali' }}">
                                            <x-icon :name="$user->is_active ? 'ban' : 'check'" class="h-3.5 w-3.5"/>
                                            <span class="hidden lg:inline">{{ $user->is_active ? 'Disable' : 'Aktifkan' }}</span>
                                        </button>
                                    </form>

                                    <form method="POST" action="{{ route('users.destroy', $user) }}"
                                          onsubmit="return confirm('Hapus pengguna {{ $user->name }}?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn-danger btn-sm"><x-icon name="trash" class="h-3.5 w-3.5"/></button>
                                    </form>
                                @endunless
                            </span>
                        @endcan
                    </div>
                </div>
            @endforeach
        </div>

        <div class="card hidden overflow-hidden md:block">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px]">
                    <thead class="border-b border-slate-100 dark:border-white/5">
                        <tr>
                            <th class="th">Nama</th>
                            <th class="th">Role</th>
                            <th class="th">Status</th>
                            <th class="th">Login Terakhir</th>
                            <th class="th text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                        @foreach ($users as $user)
                            <tr class="row-hover">
                                <td class="td">
                                    <div class="flex items-center gap-3">
                                        <span class="avatar h-9 w-9 text-sm">{{ $user->initial() }}</span>
                                        <span class="min-w-0">
                                            <span class="block truncate font-medium text-slate-700 dark:text-slate-200">{{ $user->name }}</span>
                                            <span class="block truncate text-xs text-slate-400">{{ $user->email }}</span>
                                        </span>
                                    </div>
                                </td>

                                <td class="td">
                                    <span class="badge-blue">
                                        <x-icon :name="$user->role?->name?->icon() ?? 'users'" class="h-3 w-3"/>
                                        {{ $user->roleLabel() }}
                                    </span>
                                </td>

                                <td class="td">
                                    @if ($user->is_active)
                                        <span class="badge-green">Aktif</span>
                                    @else
                                        <span class="badge-red"><x-icon name="ban" class="h-3 w-3"/> Disable</span>
                                    @endif
                                    @if ($schedule = $user->loginScheduleLabel())
                                        <span class="badge-amber mt-1 whitespace-nowrap" title="Jadwal login"><x-icon name="clock" class="h-3 w-3"/> {{ $schedule }}</span>
                                    @endif
                                </td>

                                <td class="td whitespace-nowrap text-xs text-slate-400">
                                    {{ $user->last_login_at?->translatedFormat('d M Y, H:i') ?? 'Belum pernah' }}
                                </td>

                                <td class="td">
                                    @can(App\Enums\Permission::ManageUsers->value)
                                        <div class="flex justify-end gap-2">
                                            <a href="{{ route('users.edit', $user) }}" class="btn-outline btn-sm">
                                                <x-icon name="edit" class="h-3.5 w-3.5"/>
                                            </a>

                                            @unless ($user->is(auth()->user()))
                                                <form method="POST" action="{{ route('users.toggle-active', $user) }}"
                                                      onsubmit="return confirm('{{ $user->is_active ? 'Disable' : 'Aktifkan kembali' }} akun {{ $user->name }}?')">
                                                    @csrf
                                                    <button class="{{ $user->is_active ? 'btn-outline' : 'btn-success' }} btn-sm" title="{{ $user->is_active ? 'Disable — tidak bisa login' : 'Aktifkan kembali' }}">
                                                        <x-icon :name="$user->is_active ? 'ban' : 'check'" class="h-3.5 w-3.5"/>
                                                        <span class="hidden lg:inline">{{ $user->is_active ? 'Disable' : 'Aktifkan' }}</span>
                                                    </button>
                                                </form>

                                                <form method="POST" action="{{ route('users.destroy', $user) }}"
                                                      onsubmit="return confirm('Hapus pengguna {{ $user->name }}?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="btn-danger btn-sm"><x-icon name="trash" class="h-3.5 w-3.5"/></button>
                                                </form>
                                            @endunless
                                        </div>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="card">
            <x-empty-state icon="users" title="Tidak ada pengguna"
                           description="Belum ada akun yang cocok dengan filter ini."/>
        </div>
    @endif

    @if ($users->hasPages())
        <div class="mt-6">{{ $users->links() }}</div>
    @endif
</x-layouts.app>
