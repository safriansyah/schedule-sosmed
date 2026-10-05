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
                Pilih wilayah di filter, lalu bagikan ke operator — tiketnya langsung dibuat atas nama operator itu.
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

    {{-- Two counts: who still has no ticket in this filter, and who holds the
         open tickets in the chosen region (what "Pindah Ticket" can move). --}}
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
                    <p class="text-xs text-slate-500 dark:text-slate-400">Belum punya tiket pada filter ini</p>
                </div>
            </div>
        </div>

        <div class="card border-violet-500/30 bg-violet-500/[0.04] p-4 dark:bg-violet-500/[0.08]">
            <div class="flex items-center gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-violet-500/15 text-violet-600 dark:text-violet-300">
                    <x-icon name="users" class="h-5 w-5"/>
                </span>
                <div class="min-w-0">
                    @if ($regionChosen)
                        <p class="text-2xl font-extrabold tracking-tight text-slate-800 dark:text-white">
                            {{ number_format($regionHolders->sum()) }}
                        </p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Tiket terbuka, dipegang {{ $regionHolders->count() }} operator di wilayah ini
                        </p>
                    @else
                        <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">Pilih wilayah</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            untuk melihat siapa yang memegang tiket di sana
                        </p>
                    @endif
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

    {{-- The chosen region, shown once above both forms. Both act on it. --}}
    <div class="mb-4 rounded-2xl border p-4 {{ $regionChosen ? 'border-slate-200 dark:border-white/10' : 'border-amber-500/40 bg-amber-500/[0.06]' }}">
        @if ($regionChosen)
            <p class="text-[11px] uppercase tracking-wide text-slate-400">Wilayah terpilih</p>
            <p class="mt-1 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ implode(' › ', $regionChosen) }}</p>
        @else
            <p class="flex items-center gap-1.5 text-sm font-semibold text-amber-700 dark:text-amber-400">
                <x-icon name="alert" class="h-4 w-4"/> Belum ada wilayah dipilih
            </p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                Pilih minimal satu tingkat wilayah di filter atas — Kabupaten/Kota, Kecamatan, Kelurahan, atau Pokjar/SALUT.
                Tanpa wilayah, tidak ada tiket yang bisa dibuat atau dipindah.
            </p>
        @endif
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        {{-- A. Buat Ticket per Wilayah: straight to an operator, no assigning. --}}
        <form method="POST" action="{{ route('students.assign.region') }}" class="card p-5">
            @csrf

            <h2 class="mb-1 flex items-center gap-2 text-base font-bold text-slate-800 dark:text-white">
                <x-icon name="file-text" class="h-4 w-4 text-brand-500"/> Buat Ticket per Wilayah
            </h2>
            <p class="mb-4 text-xs text-slate-400">
                Setiap mahasiswa di wilayah terpilih yang belum punya tiket langsung dibuatkan tiket atas nama operator
                yang dipilih (source <span class="font-medium text-slate-500 dark:text-slate-300">Import Mahasiswa</span>).
                Mahasiswa yang sudah punya tiket dilewati, jadi aman ditekan dua kali.
            </p>

            @error('kabupaten') <p class="form-error mb-3">{{ $message }}</p> @enderror
            @error('generate') <p class="form-error mb-3">{{ $message }}</p> @enderror

            {{-- The chosen region travels as hidden inputs, so the action works
                 on exactly what the list above is showing. --}}
            @foreach ($regionLevels as $level)
                <input type="hidden" name="{{ $level }}" value="{{ $filters[$level] ?? '' }}">
            @endforeach

            @if ($regionChosen)
                <p class="mb-3 text-xs text-slate-500 dark:text-slate-400">
                    <strong class="text-slate-700 dark:text-slate-200">{{ number_format($matching) }}</strong> mahasiswa di wilayah ini belum punya tiket.
                </p>
            @endif

            <div>
                <label for="operator_region" class="label">Operator <span class="text-rose-500">*</span></label>
                <select id="operator_region" name="operator_id" class="input" required>
                    <option value="">Pilih operator…</option>
                    <x-operator-options :operators="$operators"
                        />
                </select>
                @error('operator_id') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <button class="btn-primary mt-4 w-full" @disabled($regionChosen === [] || $matching === 0)>
                <x-icon name="file-text" class="h-4 w-4"/>
                Buat {{ $regionChosen ? number_format($matching).' ' : '' }}Ticket
            </button>
        </form>

        {{-- B. Pindah Ticket: undo a region given to the wrong operator. --}}
        <form method="POST" action="{{ route('students.assign.move') }}" class="card p-5"
              onsubmit="return confirm('Pindahkan tiket wilayah ini ke operator tujuan?')">
            @csrf

            <h2 class="mb-1 flex items-center gap-2 text-base font-bold text-slate-800 dark:text-white">
                <x-icon name="rotate" class="h-4 w-4 text-violet-500"/> Pindah Ticket
            </h2>
            <p class="mb-4 text-xs text-slate-400">
                Salah memilih operator untuk sebuah wilayah? Semua tiket terbuka milik operator asal di wilayah terpilih
                dipindah ke operator tujuan. Tiket yang sudah ditutup tidak ikut. Tercatat di riwayat tiket.
            </p>

            @error('move') <p class="form-error mb-3">{{ $message }}</p> @enderror

            @foreach ($regionLevels as $level)
                <input type="hidden" name="{{ $level }}" value="{{ $filters[$level] ?? '' }}">
            @endforeach

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="from_operator" class="label">Dari operator <span class="text-rose-500">*</span></label>
                    <select id="from_operator" name="from_operator_id" class="input" required @disabled($regionHolders->isEmpty())>
                        <option value="">{{ $regionHolders->isEmpty() ? ($regionChosen ? 'Belum ada yang memegang' : 'Pilih wilayah dulu') : 'Pilih operator asal…' }}</option>
                        @foreach ($regionHolders->sortDesc() as $userId => $total)
                            <option value="{{ $userId }}" @selected((string) old('from_operator_id') === (string) $userId)>
                                {{ $holderNames[$userId] ?? 'Pengguna #'.$userId }} — {{ number_format($total) }} tiket
                            </option>
                        @endforeach
                    </select>
                    @error('from_operator_id') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="to_operator" class="label">Ke operator <span class="text-rose-500">*</span></label>
                    <select id="to_operator" name="to_operator_id" class="input" required @disabled($regionHolders->isEmpty())>
                        <option value="">Pilih operator tujuan…</option>
                        <x-operator-options :operators="$operators" :selected="old('to_operator_id')"/>
                    </select>
                    @error('to_operator_id') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <button class="btn-outline mt-4 w-full" @disabled($regionHolders->isEmpty())>
                <x-icon name="rotate" class="h-4 w-4"/> Pindahkan Tiket
            </button>
        </form>
    </div>

    {{-- Manual select: picking individual rows is still the right tool for
         the handful of exceptions a region rule cannot express. --}}
    <form method="POST" action="{{ route('students.tickets.selected') }}" class="mt-6"
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
                        <x-operator-options :operators="$operators"/>
                    </select>

                    <button class="btn-primary" x-bind:disabled="count === 0">
                        <x-icon name="file-text" class="h-4 w-4"/> Buat Ticket Terpilih
                    </button>
                </div>
            </div>

            @if ($students->isEmpty())
                <x-empty-state icon="check-circle" title="Semua sudah punya tiket"
                               description="Semua mahasiswa pada filter ini sudah dibuatkan tiket."/>
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
                                <th class="px-4 py-3 font-semibold">Pemegang</th>
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
                                        <span class="text-xs text-slate-500 dark:text-slate-400">{{ $student->assignee?->name ?? '—' }}</span>
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
