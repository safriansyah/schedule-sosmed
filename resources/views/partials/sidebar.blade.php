@php
    use App\Enums\Permission;

    /**
     * Two-level navigation.
     *
     * An item is either a link (`route`) or a group (`children`). Every entry
     * declares the permission it needs; items only render when the route exists
     * AND the user may reach it, so no menu is ever a 403 trap. A group with no
     * visible children disappears entirely.
     *
     * `null` permission = everyone.
     */
    $sections = [
        'Utama' => [
            ['route' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'grid', 'active' => 'dashboard', 'can' => Permission::ViewDashboard],
            ['route' => 'reports.index', 'label' => 'Laporan', 'icon' => 'pie', 'active' => 'reports.index', 'can' => Permission::ViewReports],
        ],

        'Konten' => [
            [
                'label' => 'Produksi Konten', 'icon' => 'image', 'active' => 'contents.*|calendar.*|approvals.*|verifications.*',
                'children' => [
                    ['route' => 'contents.index', 'label' => 'Konten', 'active' => 'contents.*', 'can' => Permission::ViewAnyContent],
                    ['route' => 'calendar.index', 'label' => 'Kalender', 'active' => 'calendar.*', 'can' => Permission::ViewCalendar],
                    ['route' => 'approvals.index', 'label' => 'Approval', 'active' => 'approvals.*', 'can' => Permission::ViewApproval],
                    ['route' => 'verifications.index', 'label' => 'Verifikasi', 'active' => 'verifications.*', 'can' => Permission::ViewVerification],
                ],
            ],
        ],

        'Interaksi & CRM' => [
            [
                'label' => 'Inbox Interaksi', 'icon' => 'inbox', 'active' => 'interactions.*',
                'children' => [
                    ['route' => 'interactions.index', 'label' => 'Negatif Urgent', 'query' => ['tab' => 'urgent'], 'tab' => 'urgent', 'can' => Permission::ViewInteractions],
                    ['route' => 'interactions.index', 'label' => 'Pertanyaan', 'query' => ['tab' => 'question'], 'tab' => 'question', 'can' => Permission::ViewInteractions],
                    ['route' => 'interactions.index', 'label' => 'Perlu Dibalas', 'query' => ['tab' => 'needs_reply'], 'tab' => 'needs_reply', 'can' => Permission::ViewInteractions],
                    ['route' => 'interactions.index', 'label' => 'Tugas Saya', 'query' => ['tab' => 'mine'], 'tab' => 'mine', 'can' => Permission::ViewInteractions],
                    ['route' => 'interactions.index', 'label' => 'Semua Interaksi', 'query' => ['tab' => 'all'], 'tab' => 'all', 'can' => Permission::ViewInteractions],
                    ['route' => 'interactions.manual', 'label' => 'Catat Manual', 'active' => 'interactions.manual', 'can' => Permission::HandleInteractions],
                    ['route' => 'interactions.accuracy', 'label' => 'Akurasi AI', 'active' => 'interactions.accuracy', 'can' => Permission::ViewInteractions],
                ],
            ],
            [
                'label' => 'Database', 'icon' => 'users', 'active' => 'contacts.*',
                'children' => [
                    ['route' => 'contacts.index', 'label' => 'Kontak (UID)', 'active' => 'contacts.index|contacts.show', 'can' => Permission::ViewContacts],
                    ['route' => 'contacts.agents', 'label' => 'Agent', 'active' => 'contacts.agents', 'can' => Permission::ViewContacts],
                ],
            ],
        ],

        /*
         * Each module carries its OWN report, rather than one "Laporan" menu
         * holding all three. Nobody reads all three: ticket handling, student
         * data and task planning belong to different people, and a combined
         * page meant each of them scrolled past two thirds of it.
         */
        'Penanganan' => [
            [
                'label' => 'Ticketing', 'icon' => 'file-text', 'active' => 'tickets.*|reports.tickets',
                'children' => [
                    ['route' => 'tickets.index', 'label' => 'Semua Tiket', 'active' => 'tickets.index|tickets.show|tickets.create', 'can' => Permission::ViewTickets],
                    ['route' => 'tickets.mine', 'label' => 'Tiket Saya', 'active' => 'tickets.mine', 'can' => Permission::ViewTickets],
                    ['route' => 'reports.tickets', 'label' => 'Laporan Ticketing', 'active' => 'reports.tickets', 'can' => Permission::ViewReports],
                    ['route' => 'tickets.categories.index', 'label' => 'Kategori', 'active' => 'tickets.categories.*', 'can' => Permission::ManageTicketCategories],
                    ['route' => 'tickets.settings.edit', 'label' => 'Format ID Tiket', 'active' => 'tickets.settings.*', 'can' => Permission::ManageSettings],
                ],
            ],
            [
                'label' => 'Data Mahasiswa', 'icon' => 'id-card', 'active' => 'students.*|reports.students',
                'children' => [
                    ['route' => 'students.index', 'label' => 'Daftar Mahasiswa', 'active' => 'students.index|students.show', 'can' => Permission::ViewStudents],
                    ['route' => 'students.unsigned', 'label' => 'Unsigned & Ticket', 'active' => 'students.unsigned|students.unassigned', 'can' => Permission::AssignStudents],
                    ['route' => 'students.import.index', 'label' => 'Import Data', 'active' => 'students.import.*', 'can' => Permission::ImportStudents],
                    ['route' => 'reports.students', 'label' => 'Laporan Mahasiswa', 'active' => 'reports.students', 'can' => Permission::ViewReports],
                ],
            ],
            [
                'label' => 'Task Management', 'icon' => 'calendar', 'active' => 'tasks.*|reports.tasks',
                'children' => [
                    ['route' => 'tasks.index', 'label' => 'Papan Rencana', 'active' => 'tasks.index|tasks.show', 'can' => Permission::ViewTasks],
                    ['route' => 'reports.tasks', 'label' => 'Laporan Task', 'active' => 'reports.tasks', 'can' => Permission::ViewReports],
                ],
            ],
        ],

        'Monitoring & Analytic' => [
            [
                'label' => 'Monitoring', 'icon' => 'monitor', 'active' => 'monitoring.*',
                'children' => [
                    ['route' => 'monitoring.index', 'label' => 'Ringkasan Akun', 'active' => 'monitoring.index|monitoring.show', 'can' => Permission::ViewMonitoring],
                    ['route' => 'monitoring.comments', 'label' => 'Komentar per Akun', 'active' => 'monitoring.comments', 'can' => Permission::ViewMonitoring],
                ],
            ],
            [
                'label' => 'Analytic', 'icon' => 'chart', 'active' => 'analytics.*|datasets.*',
                'children' => [
                    ['route' => 'analytics.index', 'label' => 'Analytics', 'active' => 'analytics.*', 'can' => Permission::ViewAnalytics],
                    ['route' => 'datasets.index', 'label' => 'UT Monitoring Account', 'active' => 'datasets.*', 'can' => Permission::ViewDatasets],
                ],
            ],
        ],

        'Sistem' => [
            [
                'label' => 'Pengaturan Sistem', 'icon' => 'settings', 'active' => 'accounts.*|users.*|activities.*|settings.*',
                'children' => [
                    ['route' => 'accounts.index', 'label' => 'Akun Sosmed', 'active' => 'accounts.*', 'can' => Permission::ViewAccounts],
                    ['route' => 'users.index', 'label' => 'Pengguna', 'active' => 'users.*', 'can' => Permission::ViewUsers],
                    ['route' => 'activities.index', 'label' => 'Aktivitas', 'active' => 'activities.*', 'can' => Permission::ViewActivity],
                    ['route' => 'settings.site.edit', 'label' => 'Identitas Website', 'active' => 'settings.site.*', 'can' => Permission::ManageSettings],
                    ['route' => 'settings.edit', 'label' => 'Pengaturan Akun', 'active' => 'settings.edit|settings.profile|settings.password', 'can' => null],
                ],
            ],
        ],
    ];

    $user = auth()->user();

    /** A child is visible when its route exists and the user holds its permission. */
    $childVisible = fn (array $child) => \Route::has($child['route'])
        && ($child['can'] === null || $user->hasPermission($child['can']));

    /**
     * Tab links all point at interactions.index, so routeIs() cannot tell them
     * apart — compare the tab in the query string instead.
     */
    $childActive = function (array $child) {
        if (isset($child['tab'])) {
            return request()->routeIs('interactions.index')
                && (request()->input('tab', 'urgent') === $child['tab']);
        }

        return request()->routeIs(explode('|', $child['active'] ?? $child['route']));
    };
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
        {{-- The uploaded logo when there is one, the drawn mark otherwise —
             never an empty box. --}}
        <div class="grid h-10 w-10 shrink-0 place-items-center overflow-hidden rounded-xl bg-gradient-to-br from-brand-600 to-brand-500 text-white shadow-lg">
            @if ($branding->logoUrl())
                <img src="{{ $branding->logoUrl() }}" alt="{{ $branding->name() }}" class="h-full w-full object-cover">
            @else
                <x-icon name="send" class="h-5 w-5"/>
            @endif
        </div>
        <div x-show="!$store.ui.collapsed" class="min-w-0">
            <p class="truncate text-sm font-bold text-slate-800 dark:text-white">{{ $branding->name() }}</p>
            <p class="truncate text-[11px] text-slate-400">{{ $branding->tagline() }}</p>
        </div>
    </div>

    {{-- Nav --}}
    <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 pb-4">
        @foreach ($sections as $section => $items)
            @php
                // Resolve visibility once per section so an empty section (and
                // its heading) never renders.
                $resolved = [];

                foreach ($items as $item) {
                    if (isset($item['children'])) {
                        $children = array_values(array_filter($item['children'], $childVisible));

                        if ($children) {
                            $resolved[] = [...$item, 'children' => $children];
                        }
                    } elseif ($childVisible($item)) {
                        $resolved[] = $item;
                    }
                }
            @endphp

            @if ($resolved)
                <p class="nav-section" x-show="!$store.ui.collapsed">{{ $section }}</p>

                @foreach ($resolved as $item)
                    @php
                        $isGroup = isset($item['children']);
                        $open = request()->routeIs(explode('|', $item['active']));
                        $badge = $navBadges[$item['route'] ?? $item['label']] ?? 0;
                    @endphp

                    @if (! $isGroup)
                        {{-- Plain link --}}
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
                    @else
                        {{-- Group: a header that expands its children. Starts open
                             when one of them is the current page, so a deep link
                             never lands on a collapsed menu. --}}
                        <div x-data="{ open: @js($open) }" class="select-none">
                            <button type="button"
                                    @click="$store.ui.collapsed ? $store.ui.collapsed = false : open = !open"
                                    @class(['nav-link w-full', 'text-slate-900 dark:text-white' => $open])
                                    :class="$store.ui.collapsed && 'lg:justify-center lg:px-0'"
                                    title="{{ $item['label'] }}">
                                <span class="relative shrink-0">
                                    <x-icon :name="$item['icon']" class="h-5 w-5 {{ $open ? 'text-brand-600 dark:text-brand-400' : '' }}"/>
                                    @if ($badge > 0)
                                        <span x-show="$store.ui.collapsed"
                                              class="absolute -right-1.5 -top-1.5 h-2 w-2 rounded-full bg-rose-500 ring-2 ring-white dark:ring-ink-900"></span>
                                    @endif
                                </span>

                                <span x-show="!$store.ui.collapsed" class="flex-1 text-left">{{ $item['label'] }}</span>

                                @if ($badge > 0)
                                    <span x-show="!$store.ui.collapsed"
                                          class="grid h-5 min-w-5 place-items-center rounded-full bg-rose-500 px-1.5 text-[10px] font-bold text-white">
                                        {{ $badge > 9 ? '9+' : $badge }}
                                    </span>
                                @endif

                                <x-icon name="chevron-down" x-show="!$store.ui.collapsed"
                                        class="h-4 w-4 shrink-0 text-slate-400 transition-transform duration-200"
                                        ::class="open && 'rotate-180'"/>
                            </button>

                            <div x-show="open && !$store.ui.collapsed" x-collapse x-cloak>
                                <div class="ml-[1.6rem] mt-0.5 space-y-0.5 border-l border-slate-200 pl-3 dark:border-white/10">
                                    @foreach ($item['children'] as $child)
                                        @php
                                            $childBadge = $navBadges[$child['route'].($child['tab'] ?? '')] ?? 0;
                                        @endphp
                                        <a href="{{ route($child['route'], $child['query'] ?? []) }}"
                                           @class(['nav-sublink', 'nav-sublink-active' => $childActive($child)])>
                                            <span class="flex-1 truncate">{{ $child['label'] }}</span>
                                            @if ($childBadge > 0)
                                                <span class="grid h-4 min-w-4 place-items-center rounded-full bg-rose-500 px-1 text-[9px] font-bold text-white">
                                                    {{ $childBadge > 99 ? '99+' : $childBadge }}
                                                </span>
                                            @endif
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif
                @endforeach
            @endif
        @endforeach
    </nav>

    {{-- User --}}
    <div class="border-t border-slate-200/70 p-3 dark:border-white/[0.06]">
        <div class="flex items-center gap-3 rounded-xl px-2 py-2">
            <div class="avatar h-9 w-9 text-sm">{{ $user->initial() }}</div>

            <div x-show="!$store.ui.collapsed" class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $user->name }}</p>
                <p class="truncate text-[11px] text-slate-400">{{ $user->role?->label ?? $user->email }}</p>
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
