@php
    use App\Enums\Permission;

    /**
     * Navigation grouped into sections. Each item declares the permission it
     * needs; items are only shown when the route exists AND the user may reach
     * it — so no menu is ever a 403 trap. `null` permission = everyone.
     */
    $sections = [
        'Utama' => [
            ['route' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'grid', 'active' => 'dashboard', 'can' => Permission::ViewDashboard],
        ],
        'Konten' => [
            ['route' => 'contents.index', 'label' => 'Konten', 'icon' => 'image', 'active' => 'contents.*', 'can' => Permission::ViewAnyContent],
            ['route' => 'calendar.index', 'label' => 'Kalender', 'icon' => 'calendar', 'active' => 'calendar.*', 'can' => Permission::ViewCalendar],
            ['route' => 'approvals.index', 'label' => 'Approval', 'icon' => 'check-circle', 'active' => 'approvals.*', 'can' => Permission::ViewApproval],
            ['route' => 'verifications.index', 'label' => 'Verifikasi', 'icon' => 'badge-check', 'active' => 'verifications.*', 'can' => Permission::ViewVerification],
        ],
        'Monitoring & Analytic' => [
            ['route' => 'monitoring.index', 'label' => 'Monitoring', 'icon' => 'monitor', 'active' => 'monitoring.*', 'can' => Permission::ViewMonitoring],
            ['route' => 'analytics.index', 'label' => 'Analytics', 'icon' => 'chart', 'active' => 'analytics.*', 'can' => Permission::ViewAnalytics],
        ],
        'UT Monitoring Account' => [
            ['route' => 'datasets.index', 'label' => 'UT Monitoring Account', 'icon' => 'database', 'active' => 'datasets.*', 'can' => Permission::ViewDatasets],
        ],
        'Sistem' => [
            ['route' => 'accounts.index', 'label' => 'Akun Sosmed', 'icon' => 'link', 'active' => 'accounts.*', 'can' => Permission::ViewAccounts],
            ['route' => 'users.index', 'label' => 'Pengguna', 'icon' => 'users', 'active' => 'users.*', 'can' => Permission::ViewUsers],
            ['route' => 'activities.index', 'label' => 'Aktivitas', 'icon' => 'activity', 'active' => 'activities.*', 'can' => Permission::ViewActivity],
            ['route' => 'settings.edit', 'label' => 'Pengaturan', 'icon' => 'settings', 'active' => 'settings.*', 'can' => null],
        ],
    ];

    $user = auth()->user();
@endphp

<aside
    class="fixed inset-y-0 left-0 z-40 flex flex-col border-r border-slate-200/70 bg-white/95 backdrop-blur-xl
           transition-all duration-300 dark:border-white/[0.06] dark:bg-ink-900/95
           lg:static lg:translate-x-0"
    :class="[
        $store.ui.sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
        $store.ui.collapsed ? 'w-72 lg:w-20' : 'w-72'
    ]"
>
    {{-- Brand --}}
    <div class="flex h-16 shrink-0 items-center gap-3 px-5">
        <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-brand-600 to-brand-500 text-white shadow-lg">
            <x-icon name="send" class="h-5 w-5"/>
        </div>
        <div x-show="!$store.ui.collapsed" class="min-w-0">
            <p class="truncate text-sm font-bold text-slate-800 dark:text-white">{{ config('app.name') }}</p>
            <p class="truncate text-[11px] text-slate-400">Sosmed Management</p>
        </div>
    </div>

    {{-- Nav --}}
    <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 pb-4">
        @foreach ($sections as $section => $items)
            @php
                $visible = array_filter($items, fn ($i) =>
                    Route::has($i['route']) && ($i['can'] === null || $user->hasPermission($i['can'])));
            @endphp

            @if (filled($visible))
                <p class="nav-section" x-show="!$store.ui.collapsed">{{ $section }}</p>

                @foreach ($visible as $item)
                    @php $badge = $navBadges[$item['route']] ?? 0; @endphp
                    <a href="{{ route($item['route']) }}"
                       @class(['nav-link', 'nav-link-active' => request()->routeIs($item['active'])])
                       :class="$store.ui.collapsed && 'lg:justify-center lg:px-0'"
                       title="{{ $item['label'] }}">
                        <span class="relative shrink-0">
                            <x-icon :name="$item['icon']" class="h-5 w-5"/>
                            @if ($badge > 0)
                                <span x-show="$store.ui.collapsed"
                                      class="absolute -right-1.5 -top-1.5 h-2 w-2 rounded-full bg-rose-500 ring-2 ring-white dark:ring-ink-900"></span>
                            @endif
                        </span>
                        <span x-show="!$store.ui.collapsed" class="flex-1">{{ $item['label'] }}</span>
                        @if ($badge > 0)
                            <span x-show="!$store.ui.collapsed"
                                  class="grid h-5 min-w-5 place-items-center rounded-full bg-rose-500 px-1.5 text-[10px] font-bold text-white">
                                {{ $badge > 9 ? '9+' : $badge }}
                            </span>
                        @endif
                    </a>
                @endforeach
            @endif
        @endforeach
    </nav>

    {{-- User --}}
    <div class="border-t border-slate-200/70 p-3 dark:border-white/[0.06]">
        <div class="flex items-center gap-3 rounded-xl px-2 py-2">
            <div class="avatar h-9 w-9 text-sm">{{ auth()->user()->initial() }}</div>

            <div x-show="!$store.ui.collapsed" class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-slate-700 dark:text-slate-200">{{ auth()->user()->name }}</p>
                <p class="truncate text-[11px] text-slate-400">{{ auth()->user()->email }}</p>
            </div>

            <form x-show="!$store.ui.collapsed" method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="text-slate-400 transition hover:text-rose-500" title="Keluar">
                    <x-icon name="logout" class="h-5 w-5"/>
                </button>
            </form>
        </div>
    </div>
</aside>
