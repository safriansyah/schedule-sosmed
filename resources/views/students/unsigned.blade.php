@php
    use App\Enums\Permission;

    // Only the filters the two action endpoints actually read. Carrying
    // anything else as a hidden input is misleading: it would look like it
    // narrows the action when it does not.
    $carried = array_filter([
        'q' => $filters['q'] ?? null,
        'kondisi' => $filters['kondisi'] ?? null,
        'semester' => $filters['semester'] ?? null,
        'segmen' => $filters['segmen'] ?? null,
        'import' => $filters['import'] ?? null,
    ] + array_intersect_key($filters, array_flip($regionLevels)), fn ($v) => filled($v));

    $regionChosen = array_filter(array_intersect_key($filters, array_flip($regionLevels)), fn ($v) => filled($v));
@endphp

<x-layouts.app title="Unsigned & Ticket">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Unsigned &amp; Ticket</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Satu layar, dua proses: buat tiket untuk daftar ini, lalu bagikan wilayahnya ke operator.
            </p>
        </div>

        <a href="{{ route('students.index') }}" class="btn-outline">
            <x-icon name="users" class="h-4 w-4"/> Semua Mahasiswa
        </a>
    </x-slot:header>

    {{-- The filter form IS the region picker. Deliberately one thing and not
         two: the list below always shows exactly the people the buttons will
         act on, so nobody has to hold two different selections in their head. --}}
    <form method="GET" id="student-filter" class="card mb-4 p-4">
        <div class="grid gap-3 lg:grid-cols-12">
            <div class="lg:col-span-4">
                <label for="q" class="label">Cari</label>
                <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="input" placeholder="NIM, NAC, nama…">
            </div>

            <div class="lg:col-span-4">
                <label for="kondisi" class="label">Kondisi</label>
                <select id="kondisi" name="kondisi" class="input" onchange="this.form.submit()">
                    <option value="">Semua kondisi</option>
                    @foreach ($conditions as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['kondisi'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="lg:col-span-4">
                <label for="import" class="label">Asal data</label>
                <select id="import" name="import" class="input">
                    <option value="">Semua import</option>
                    @foreach ($imports as $id => $label)
                        <option value="{{ $id }}" @selected((string) ($filters['import'] ?? '') === (string) $id)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-end gap-2 lg:col-span-4">
                <button class="btn-primary flex-1"><x-icon name="filter" class="h-4 w-4"/> Terapkan</button>
                @if ($carried)
                    <a href="{{ route('students.unsigned') }}" class="btn-outline shrink-0">Reset</a>
                @endif
            </div>
        </div>

        {{-- Region, widest first. Each level narrows the next; a level the
             imported file does not carry is shown disabled rather than hidden,
             because "this export has no kelurahan" is information and a
             selector that silently vanishes looks like a bug. --}}
        <div class="mt-3 border-t border-slate-200 pt-3 dark:border-white/5">
            <p class="label mb-2">Wilayah</p>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                @foreach ($regionLevels as $level)
                    @php $options = $regions[$level] ?? []; @endphp
                    <div>
                        <label for="region-{{ $level }}" class="mb-1 block text-[11px] font-medium text-slate-500 dark:text-slate-400">
                            {{ $regionLabels[$level] }}
                            <span class="text-slate-300 dark:text-slate-600">({{ count($options) }})</span>
                        </label>

                        <select id="region-{{ $level }}" name="{{ $level }}" class="input !py-2 !text-xs"
                                onchange="this.form.submit()" @disabled(count($options) === 0)>
                            <option value="">{{ count($options) === 0 ? 'Tidak ada data' : 'Semua' }}</option>
                            @foreach ($options as $name)
                                <option value="{{ $name }}" @selected(($filters[$level] ?? '') === $name)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
            </div>
        </div>
    </form>

    {{-- Two counts, not one: they answer different questions and the buttons
         below act on different sets. Saying "350 mahasiswa" once and wiring
         two buttons to it is how an admin presses the wrong one. --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2">
        <div class="card border-brand-500/30 bg-brand-500/[0.04] p-4 dark:bg-brand-500/[0.08]">
            <div class="flex items-center gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-brand-500/15 text-brand-600 dark:text-brand-300">
                    <x-icon name="target" class="h-5 w-5"/>
                </span>
                <div class="min-w-0">
                    <p class="text-2xl font-extrabold tracking-tight text-slate-800 dark:text-white">
                        {{ number_format($matching) }}
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Belum assigned pada filter ini</p>
                </div>
            </div>
        </div>

        <div class="card border-violet-500/30 bg-violet-500/[0.04] p-4 dark:bg-violet-500/[0.08]">
            <div class="flex items-center gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-violet-500/15 text-violet-600 dark:text-violet-300">
                    <x-icon name="file-text" class="h-5 w-5"/>
                </span>
                <div class="min-w-0">
                    <p class="text-2xl font-extrabold tracking-tight text-slate-800 dark:text-white">
                        {{ number_format($ticketPending) }}
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Belum punya tiket, dari {{ number_format($ticketScope) }} mahasiswa pada filter ini
                    </p>
                </div>
            </div>
        </div>
    </div>

    @if ($carried)
        <p class="mb-4 text-xs text-slate-400">
            Filter aktif: <span class="font-medium text-slate-500 dark:text-slate-300">{{ implode(' · ', $carried) }}</span>
        </p>
    @endif

    {{-- Without an operator to hand work to, the assign form below would fail
         validation with no way to fix it from here. Say so, and link to where
         it IS fixable. --}}
    @if ($operators->isEmpty())
        <div class="card mb-6 border-amber-500/30 bg-amber-500/[0.06] p-4">
            <div class="flex flex-wrap items-start gap-3">
                <x-icon name="alert" class="mt-0.5 h-5 w-5 shrink-0 text-amber-500"/>
                <div class="min-w-0 flex-1">
                    <p class="font-semibold text-amber-700 dark:text-amber-400">Belum ada operator</p>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                        Mahasiswa hanya bisa dibagikan ke pengguna ber-role Operator, PIC, atau Manager.
                        Buat penggunanya dulu.
                    </p>
                </div>

                @can(Permission::ManageUsers->value)
                    <a href="{{ route('users.create') }}" class="btn-primary shrink-0">
                        <x-icon name="user-plus" class="h-4 w-4"/> Tambah Pengguna
                    </a>
                @endcan
            </div>
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-5">
        {{-- A. Generate Ticket --}}
        <form method="POST" action="{{ route('students.tickets.generate') }}" class="card p-5 xl:col-span-2"
              x-data="{ busy: false }" @submit="busy = true">
            @csrf
            @foreach ($carried as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach

            <h2 class="mb-1 text-base font-bold text-slate-800 dark:text-white">Generate Ticket</h2>
            <p class="mb-4 text-xs text-slate-400">
                Membuat satu tiket untuk setiap mahasiswa pada filter ini, dengan source
                <span class="font-medium text-slate-500 dark:text-slate-300">Import Mahasiswa</span>
                dan NIM-nya langsung tertaut. Tidak perlu memilih jumlah.
            </p>

            @error('generate') <p class="form-error mb-3">{{ $message }}</p> @enderror

            <ul class="mb-4 space-y-1.5 text-xs text-slate-500 dark:text-slate-400">
                <li class="flex gap-2">
                    <x-icon name="check" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-500"/>
                    Termasuk mahasiswa yang sudah dipegang operator — tiketnya ikut pemiliknya.
                </li>
                <li class="flex gap-2">
                    <x-icon name="check" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-500"/>
                    Aman ditekan dua kali: yang sudah punya tiket dilewati, bukan digandakan.
                </li>
            </ul>

            @if ($canGenerate)
                <button class="btn-primary w-full" @disabled($ticketPending === 0)
                        x-bind:disabled="busy" x-bind:class="busy && 'opacity-60'">
                    <x-icon name="file-text" class="h-4 w-4"/>
                    <span x-show="!busy">
                        @if ($ticketPending === 0)
                            Semua sudah punya tiket
                        @else
                            Generate {{ number_format($ticketPending) }} Tiket
                        @endif
                    </span>
                    <span x-show="busy" x-cloak>Membuat tiket…</span>
                </button>

                @if ($ticketPending > 500)
                    <p class="mt-2 text-center text-[11px] text-slate-400">
                        {{ number_format($ticketPending) }} tiket — proses ini butuh beberapa detik, jangan tutup halaman.
                    </p>
                @endif
            @else
                <p class="text-xs text-slate-400">Butuh izin membuat tiket.</p>
            @endif
        </form>

        {{-- B. Assign by region --}}
        <form method="POST" action="{{ route('students.assign.region') }}" class="card p-5 xl:col-span-3">
            @csrf

            <h2 class="mb-1 text-base font-bold text-slate-800 dark:text-white">Assign per Wilayah</h2>
            <p class="mb-4 text-xs text-slate-400">
                Seluruh mahasiswa belum-assigned di wilayah terpilih diberikan ke satu operator.
                Wilayahnya diambil dari pilihan di atas — tidak ada input jumlah.
            </p>

            @error('kabupaten') <p class="form-error mb-3">{{ $message }}</p> @enderror

            {{-- The chosen region travels as hidden inputs so the action acts
                 on exactly what the list is showing. --}}
            @foreach ($regionLevels as $level)
                <input type="hidden" name="{{ $level }}" value="{{ $filters[$level] ?? '' }}">
            @endforeach

            <div class="mb-4 rounded-xl border border-slate-200 p-3 dark:border-white/10">
                @if ($regionChosen)
                    <p class="text-[11px] uppercase tracking-wide text-slate-400">Wilayah terpilih</p>
                    <p class="mt-1 text-sm font-semibold text-slate-700 dark:text-slate-200">
                        {{ implode(' › ', $regionChosen) }}
                    </p>
                    <p class="mt-1 text-xs text-slate-400">
                        {{ number_format($matching) }} mahasiswa belum assigned di wilayah ini.
                    </p>
                @else
                    <p class="text-sm text-amber-600 dark:text-amber-400">
                        <x-icon name="alert" class="mr-1 inline h-4 w-4"/>
                        Belum ada wilayah dipilih.
                    </p>
                    <p class="mt-1 text-xs text-slate-400">
                        Pilih minimal satu tingkat wilayah di filter atas. Tanpa itu seluruh mahasiswa
                        akan ikut terbagikan sekaligus.
                    </p>
                @endif
            </div>

            <div class="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                <div>
                    <label for="operator_region" class="label">Operator</label>
                    <select id="operator_region" name="operator_id" class="input" required>
                        <option value="">Pilih operator…</option>
                        @foreach ($operators as $operator)
                            @php $load = $workload->firstWhere('user_id', $operator->id); @endphp
                            <option value="{{ $operator->id }}">
                                {{ $operator->name }} — {{ number_format($load->total ?? 0) }} mahasiswa
                            </option>
                        @endforeach
                    </select>
                    @error('operator_id') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <button class="btn-primary" @disabled($matching === 0 || $regionChosen === [])>
                    <x-icon name="user-plus" class="h-4 w-4"/> Assign Wilayah
                </button>
            </div>
        </form>
    </div>

    {{-- Manual select, unchanged: picking individual rows is still the right
         tool for the handful of exceptions a region rule cannot express. --}}
    <form method="POST" action="{{ route('students.assign.selected') }}" class="mt-6"
          {{-- The phone list and the desktop table both render a checkbox per
               student, so every id exists twice in the DOM. Counting and
               selecting therefore work on unique values; the server also
               de-duplicates, but the badge must not read double either. --}}
          x-data="{
              checked: [],
              get count() { return new Set(this.checked).size },
              selectAll(on) {
                  this.checked = on
                      ? [...new Set(Array.from($root.querySelectorAll('input[name=\'students[]\']')).map(i => i.value))]
                      : [];
              },
          }">
        @csrf

        <div class="card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4 dark:border-white/5">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <div>
                        <h2 class="text-base font-bold text-slate-800 dark:text-white">Pilih manual</h2>
                        <p class="text-xs text-slate-400">
                            Menampilkan {{ number_format($students->count()) }} dari {{ number_format($matching) }} mahasiswa.
                        </p>
                    </div>

                    {{-- A <form> cannot nest inside the assign form, so the
                         control lives in the filter form at the top of the
                         page (via the `form` attribute) and only renders
                         here. --}}
                    <div class="flex items-center gap-2">
                        <label for="per_page" class="shrink-0 text-xs text-slate-400">Tampilkan</label>

                        <select id="per_page" name="per_page" form="student-filter"
                                class="input !w-auto !py-1.5 !text-xs"
                                onchange="document.getElementById('student-filter').submit()">
                            @foreach ($perPageOptions as $option)
                                <option value="{{ $option }}" @selected((int) $perPage === $option)>{{ $option }}</option>
                            @endforeach
                        </select>

                        <span class="hidden shrink-0 text-xs text-slate-400 sm:inline">
                            dari {{ number_format($matching) }}
                        </span>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <span class="badge-violet" x-show="count > 0" x-cloak>
                        <span x-text="count"></span> dipilih
                    </span>

                    <select name="operator_id" class="input w-48" required>
                        <option value="">Pilih operator…</option>
                        @foreach ($operators as $operator)
                            <option value="{{ $operator->id }}">{{ $operator->name }}</option>
                        @endforeach
                    </select>

                    <button class="btn-primary" x-bind:disabled="count === 0">
                        <x-icon name="check" class="h-4 w-4"/> Assign Terpilih
                    </button>
                </div>
            </div>

            @if ($students->isEmpty())
                <x-empty-state icon="check-circle" title="Tidak ada yang belum assigned"
                               description="Semua mahasiswa pada filter ini sudah dibagikan."/>
            @else
                {{-- Phone: a tick-box list instead of a five-column table.
                     Selecting students is the core admin action here, and it
                     must not require sideways scrolling to reach the box. --}}
                <div class="divide-y divide-slate-100 md:hidden dark:divide-white/5">
                    @foreach ($students as $student)
                        <label class="flex cursor-pointer items-start gap-3 p-4 transition hover:bg-slate-50 dark:hover:bg-white/[0.02]">
                            <input type="checkbox" name="students[]" value="{{ $student->id }}" x-model="checked"
                                   class="mt-1 h-4 w-4 shrink-0 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-white/20 dark:bg-ink-850">

                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-semibold text-slate-800 dark:text-white">
                                    {{ $student->name() }}
                                </span>
                                <span class="block font-mono text-[11px] text-slate-400">{{ $student->nim }}</span>

                                <span class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                    <span class="{{ $student->kategori_masalah->badge() }}">
                                        {{ $student->kategori_masalah->short() }}
                                    </span>
                                    @if ($student->pokjar)
                                        <span class="badge-slate max-w-[10rem] truncate">{{ $student->pokjar }}</span>
                                    @endif
                                </span>

                                <span class="mt-1.5 block truncate text-xs text-slate-400">
                                    {{ $student->regionLabel() }}
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-white/5 dark:bg-white/[0.02]">
                            <tr>
                                <th class="w-10 px-4 py-3">
                                    <input type="checkbox" @change="selectAll($event.target.checked)"
                                           class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-white/20 dark:bg-ink-850">
                                </th>
                                <th class="px-4 py-3 font-semibold">Mahasiswa</th>
                                <th class="px-4 py-3 font-semibold">Wilayah</th>
                                <th class="px-4 py-3 font-semibold">Kondisi</th>
                                <th class="px-4 py-3 font-semibold">Tiket</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                            @foreach ($students as $student)
                                <tr class="transition hover:bg-slate-50 dark:hover:bg-white/[0.02]">
                                    <td class="px-4 py-3">
                                        <input type="checkbox" name="students[]" value="{{ $student->id }}" x-model="checked"
                                               class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-white/20 dark:bg-ink-850">
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="font-semibold text-slate-800 dark:text-white">{{ $student->name() }}</p>
                                        <p class="font-mono text-[11px] text-slate-400">{{ $student->nim }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ $student->regionLabel() }}</td>
                                    <td class="px-4 py-3">
                                        <span class="{{ $student->kategori_masalah->badge() }}">{{ $student->kategori_masalah->short() }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($student->tickets_count ?? 0)
                                            <span class="badge-emerald">Ada</span>
                                        @else
                                            <span class="text-xs text-slate-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="mt-6">{{ $students->links() }}</div>
    </form>
</x-layouts.app>
