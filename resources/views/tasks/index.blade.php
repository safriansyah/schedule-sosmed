<x-layouts.app title="Task Management">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Task Management</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Pekerjaan dan kegiatan terjadwal. Terpisah dari Ticketing.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('public.tasks') }}" target="_blank" rel="noopener" class="btn-outline">
                <x-icon name="eye" class="h-4 w-4"/> Halaman Publik
            </a>

            @if ($canManage)
                <button type="button" @click="$dispatch('open-modal', 'new-task')" class="btn-primary">
                    <x-icon name="plus" class="h-4 w-4"/> Task Baru
                </button>
            @endif
        </div>
    </x-slot:header>

    <div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-5">
        <x-stat-card label="Total task" :value="number_format($stats['total'])" icon="calendar" tone="brand"/>
        <x-stat-card label="Direncanakan" :value="number_format($stats['planned'])" icon="clock" tone="slate"/>
        <x-stat-card label="Berjalan" :value="number_format($stats['in_progress'])" icon="refresh" tone="cyan"/>
        <x-stat-card label="Selesai" :value="number_format($stats['completed'])" icon="check-circle" tone="emerald"/>
        <x-stat-card label="Terlambat" :value="number_format($stats['overdue'])" icon="alert"
                     :tone="$stats['overdue'] > 0 ? 'rose' : 'slate'"
                     :hint="$stats['public'].' task publik'"/>
    </div>

    {{-- Window + filters --}}
    <form method="GET" class="card mb-6 p-4">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-12">
            <div class="lg:col-span-2">
                <label for="from" class="label">Dari tanggal</label>
                <input id="from" name="from" type="date" value="{{ $from->toDateString() }}" class="input">
            </div>

            <div class="lg:col-span-2">
                <label for="to" class="label">Sampai</label>
                <input id="to" name="to" type="date" value="{{ $to->toDateString() }}" class="input">
            </div>

            <div class="col-span-2 lg:col-span-3">
                <label for="q" class="label">Cari</label>
                <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="input" placeholder="Judul task…">
            </div>

            <div class="lg:col-span-2">
                <label for="status" class="label">Status</label>
                <select id="status" name="status" class="input">
                    <option value="">Semua</option>
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="lg:col-span-2">
                <label for="pic" class="label">PIC</label>
                <select id="pic" name="pic" class="input">
                    <option value="">Semua</option>
                    <option value="0" @selected(($filters['pic'] ?? '') === '0')>— Tanpa PIC —</option>
                    @foreach ($people as $person)
                        <option value="{{ $person->id }}" @selected((string) ($filters['pic'] ?? '') === (string) $person->id)>
                            {{ $person->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-span-2 flex items-end lg:col-span-1">
                <button class="btn-primary w-full"><x-icon name="filter" class="h-4 w-4"/></button>
            </div>
        </div>
    </form>

    @php
        // Quick jumps, so moving a week is one click instead of editing two
        // date fields and pressing Terapkan.
        $keep = array_filter($filters, fn ($v) => filled($v));
        $span = max(1, $from->diffInDays($to) + 1);
        $jump = fn (\Illuminate\Support\Carbon $f, \Illuminate\Support\Carbon $t) => route('tasks.index', $keep + [
            'from' => $f->toDateString(),
            'to' => $t->toDateString(),
        ]);
    @endphp

    {{-- The planner: tasks down, dates across, a checkmark in the cell.
         Replaced the Gantt bars — a proportional bar answers "how long is
         this scheduled for", while the question asked at a stand-up is "did
         this happen on Tuesday". Geometry is computed in TaskPlanner so the
         template does no date maths. --}}
    <div class="card mb-6 overflow-hidden"
         x-data="planner(@js(collect($planner['rows'])->mapWithKeys(fn ($r) => [
             $r['task']->id => collect($r['cells'])->where('checked')->pluck('iso')->values(),
         ])), @js(csrf_token()))">

        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4 dark:border-white/5">
            <div class="min-w-0">
                <h2 class="text-base font-bold text-slate-800 dark:text-white">Papan Rencana</h2>
                <p class="text-xs text-slate-400">
                    {{ $from->translatedFormat('d M Y') }} – {{ $to->translatedFormat('d M Y') }}
                    · {{ $span }} hari
                </p>
            </div>

            {{-- Window navigation. Shifting by the window's own length keeps
                 the view continuous: from a fortnight you page a fortnight. --}}
            <div class="flex flex-wrap items-center gap-1.5">
                <a href="{{ $jump($from->copy()->subDays($span), $to->copy()->subDays($span)) }}"
                   class="btn-outline !px-2.5" title="Mundur {{ $span }} hari">
                    <x-icon name="chevron-left" class="h-4 w-4"/>
                </a>

                <a href="{{ $jump(now()->startOfWeek(), now()->startOfWeek()->addDays(13)) }}"
                   class="btn-outline !px-3 text-xs">Hari ini</a>

                <a href="{{ $jump($from->copy()->addDays($span), $to->copy()->addDays($span)) }}"
                   class="btn-outline !px-2.5" title="Maju {{ $span }} hari">
                    <x-icon name="chevron-right" class="h-4 w-4"/>
                </a>

                <span class="mx-1 hidden h-5 w-px bg-slate-200 sm:block dark:bg-white/10"></span>

                <a href="{{ $jump(now()->startOfWeek(), now()->endOfWeek()) }}"
                   class="inline-flex min-h-9 items-center rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-500 transition hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-white/5">
                    Minggu ini
                </a>
                <a href="{{ $jump(now()->startOfMonth(), now()->endOfMonth()) }}"
                   class="inline-flex min-h-9 items-center rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-500 transition hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-white/5">
                    Bulan ini
                </a>
            </div>
        </div>

        {{-- Legend + the one error surface the async ticks need. A failed
             fetch that says nothing would leave the cell looking saved. --}}
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-slate-100 px-4 py-2 text-[11px] text-slate-400 dark:border-white/5">
            <span class="flex items-center gap-1.5">
                <span class="h-3 w-3 rounded bg-brand-500/20 ring-1 ring-inset ring-brand-500/40"></span> Direncanakan
            </span>
            <span class="flex items-center gap-1.5">
                <span class="grid h-3 w-3 place-items-center rounded bg-emerald-500 text-[8px] font-bold leading-none text-white">✓</span>
                Dikerjakan
            </span>
            @if ($canManage)
                <span class="hidden sm:inline">Klik sel untuk menandai.</span>
            @endif

            <span x-show="error" x-cloak x-text="error"
                  class="ml-auto rounded-lg bg-rose-500/10 px-2 py-1 font-medium text-rose-600 dark:text-rose-400"></span>
        </div>

        @if (empty($planner['rows']))
            <x-empty-state icon="calendar" title="Tidak ada task pada rentang ini"
                           description="Geser rentang tanggalnya, atau buat task baru."/>
        @else
            <div class="overflow-x-auto">
                <table class="w-full border-separate border-spacing-0 text-sm">
                    <thead>
                        {{-- Month band.
                             A month name cannot live in a day column: "Sep
                             2026" is ~40px and a day column is 36px, so it
                             spilled into its neighbour and was clipped by the
                             row height — it read as a rendering fault. Spanning
                             the days it covers is what a header is for. --}}
                        <tr>
                            <th class="sticky left-0 z-20 border-r border-slate-200 bg-white px-4 pt-3 pb-1 text-left dark:border-white/10 dark:bg-ink-900">
                                <span class="sr-only">Bulan</span>
                            </th>

                            @foreach ($planner['months'] as $band)
                                <th colspan="{{ $band['span'] }}"
                                    class="border-l border-slate-200 px-1 pt-3 pb-1 text-center text-[10px] font-bold uppercase tracking-wide text-brand-600 dark:border-white/10 dark:text-brand-400">
                                    <span class="truncate">{{ $band['label'] }}</span>
                                </th>
                            @endforeach

                            <th class="px-2 pt-3 pb-1"><span class="sr-only">Capaian</span></th>
                        </tr>

                        <tr>
                            {{-- Sticky so the task name stays readable while the
                                 dates scroll sideways on a phone. Narrower on a
                                 phone, where it would otherwise eat two thirds
                                 of the screen and leave three day columns. --}}
                            <th class="sticky left-0 z-20 w-44 min-w-[11rem] border-r border-b border-slate-200 bg-white px-4 pb-2 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-400 sm:w-64 sm:min-w-[16rem] dark:border-white/10 dark:border-b-white/5 dark:bg-ink-900">
                                Task
                            </th>

                            @foreach ($planner['days'] as $day)
                                <th @class([
                                        'w-10 min-w-[2.5rem] border-b border-slate-200 px-0 pb-2 text-center text-[10px] leading-tight dark:border-white/5',
                                        'bg-brand-500/10 text-brand-600 dark:text-brand-400' => $day['today'],
                                        'bg-slate-50/70 text-slate-400 dark:bg-white/[0.02]' => $day['weekend'] && ! $day['today'],
                                        'text-slate-400' => ! $day['today'] && ! $day['weekend'],
                                    ])
                                    title="{{ $day['date']->translatedFormat('l, j F Y') }}">
                                    <div class="{{ $day['weekend'] ? 'opacity-70' : '' }}">{{ $day['weekday'] }}</div>
                                    <div class="text-xs font-bold {{ $day['today'] ? '' : 'text-slate-500 dark:text-slate-300' }}">
                                        {{ $day['label'] }}
                                    </div>
                                </th>
                            @endforeach

                            <th class="w-20 min-w-[5rem] border-b border-slate-200 px-2 pb-2 text-center text-[11px] font-semibold uppercase tracking-wide text-slate-400 dark:border-white/5">
                                Capaian
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($planner['rows'] as $row)
                            @php $task = $row['task']; @endphp
                            <tr class="group">
                                {{-- The left column carries everything the old
                                     second list carried — status, priority,
                                     whether it is late — so the grid can be
                                     read on its own instead of scrolling past
                                     it to find out. --}}
                                <td class="sticky left-0 z-10 w-44 min-w-[11rem] border-r border-b border-slate-200 border-b-slate-100 bg-white px-4 py-2.5 transition group-hover:bg-slate-50 sm:w-64 sm:min-w-[16rem] dark:border-white/10 dark:border-b-white/5 dark:bg-ink-900 dark:group-hover:bg-white/[0.02]">
                                    <a href="{{ route('tasks.show', $task) }}"
                                       class="block truncate font-semibold text-slate-800 hover:text-brand-600 dark:text-white">
                                        {{ $task->title }}
                                    </a>

                                    <div class="mt-1 flex flex-wrap items-center gap-1">
                                        <span class="{{ $task->status->badge() }}">
                                            <x-icon :name="$task->status->icon()" class="h-3 w-3"/> {{ $task->status->label() }}
                                        </span>

                                        @if ($task->priority !== \App\Enums\Priority::Normal)
                                            <span class="{{ $task->priority->badge() }}">{{ $task->priority->label() }}</span>
                                        @endif

                                        @if ($task->isOverdue())
                                            <span class="badge-red"><x-icon name="alert" class="h-3 w-3"/> Terlambat</span>
                                        @endif

                                        @if ($task->is_public)
                                            <span class="badge-green" title="Tampil di halaman publik">
                                                <x-icon name="eye" class="h-3 w-3"/>
                                            </span>
                                        @endif
                                    </div>

                                    <p class="mt-1 truncate text-[11px] text-slate-400">
                                        {{ $task->pic?->name ?? 'Tanpa PIC' }}
                                        · {{ $task->start_date->translatedFormat('j M') }}–{{ $task->due_date->translatedFormat('j M') }}
                                        @if ($task->ticket)
                                            · <span class="font-mono">{{ $task->ticket->number }}</span>
                                        @endif
                                    </p>
                                </td>

                                @foreach ($row['cells'] as $cell)
                                    <td @class([
                                            'border-b border-slate-100 p-0.5 text-center align-middle dark:border-white/5',
                                            'bg-brand-500/[0.07]' => $cell['today'],
                                            'bg-slate-50/70 dark:bg-white/[0.02]' => $cell['weekend'] && ! $cell['today'],
                                        ])>
                                        @if ($canManage)
                                            {{-- A form per cell so the grid still
                                                 works without JavaScript; the
                                                 click handler intercepts it and
                                                 posts in the background. --}}
                                            <form method="POST" action="{{ route('tasks.check', $task) }}" class="contents"
                                                  @submit.prevent="toggle({{ $task->id }}, @js($cell['iso']), $el.action)">
                                                @csrf
                                                <input type="hidden" name="date" value="{{ $cell['iso'] }}">
                                                <button type="submit"
                                                        x-bind:title="label({{ $task->id }}, @js($cell['iso']), @js($cell['by']), @js($cell['at']), @js(\Illuminate\Support\Carbon::parse($cell['iso'])->translatedFormat('j F Y')))"
                                                        x-bind:disabled="busy[{{ $task->id }} + '|' + @js($cell['iso'])]"
                                                        x-bind:class="isChecked({{ $task->id }}, @js($cell['iso']))
                                                            ? 'bg-emerald-500 font-bold text-white shadow-sm hover:bg-emerald-600'
                                                            : '{{ $cell['planned']
                                                                ? 'bg-brand-500/15 text-transparent ring-1 ring-inset ring-brand-500/30 hover:bg-brand-500/30 hover:text-brand-600/50'
                                                                : 'text-transparent hover:bg-slate-100 dark:hover:bg-white/5' }}'"
                                                        class="grid h-8 w-full place-items-center rounded-md text-xs transition
                                                               focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/50">
                                                    ✓
                                                </button>
                                            </form>
                                        @else
                                            <span @class([
                                                    'grid h-8 w-full place-items-center rounded-md text-xs',
                                                    'bg-emerald-500 font-bold text-white' => $cell['checked'],
                                                    'bg-brand-500/15 ring-1 ring-inset ring-brand-500/30' => $cell['planned'] && ! $cell['checked'],
                                                ])
                                                @if ($cell['checked'] && $cell['by']) title="Dicentang {{ $cell['by'] }} · {{ $cell['at'] }}" @endif>
                                                {{ $cell['checked'] ? '✓' : '' }}
                                            </span>
                                        @endif
                                    </td>
                                @endforeach

                                {{-- Done vs planned, in this window. Counted from
                                     the page's own cell state so it stays right
                                     after a background tick. --}}
                                <td class="border-b border-slate-100 px-2 py-2.5 text-center dark:border-white/5">
                                    <span class="text-sm font-bold text-slate-700 dark:text-slate-200"
                                          x-text="done({{ $task->id }}, @js(collect($row['cells'])->pluck('iso')))">{{ $row['done'] }}</span><span
                                        class="text-xs text-slate-400">/{{ $row['planned'] }}</span>

                                    <div class="mx-auto mt-1 h-1 w-10 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                                        <div class="h-full rounded-full bg-emerald-500 transition-all"
                                             x-bind:style="'width: ' + share({{ $task->id }}, @js(collect($row['cells'])->pluck('iso')), {{ max(1, $row['planned']) }}) + '%'"></div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="border-t border-slate-100 px-4 py-2.5 text-[11px] text-slate-400 dark:border-white/5">
                Capaian = hari dikerjakan dibanding hari direncanakan pada rentang ini.
                Memindahkan tenggat tidak menghapus centang yang sudah tercatat.
            </p>
        @endif
    </div>

    <script>
        /**
         * The planner's cells.
         *
         * Ticking posts in the background instead of reloading: a reload threw
         * away the page scroll AND the grid's horizontal scroll, so marking
         * five days meant finding your place again five times. The cell flips
         * immediately and is put back if the server refuses, so the grid never
         * shows a tick that was not saved.
         */
        function planner(initial, token) {
            return {
                checks: initial,
                busy: {},
                error: '',

                key(task, date) { return task + '|' + date },

                isChecked(task, date) {
                    return (this.checks[task] || []).includes(date)
                },

                /** Ticks inside this window only — the counter must match the grid. */
                done(task, window) {
                    return (this.checks[task] || []).filter(d => window.includes(d)).length
                },

                share(task, window, planned) {
                    return Math.min(100, Math.round(this.done(task, window) / planned * 100))
                },

                label(task, date, by, at, pretty) {
                    if (!this.isChecked(task, date)) return 'Tandai dikerjakan — ' + pretty
                    return by ? 'Dicentang ' + by + ' · ' + at : 'Dikerjakan — ' + pretty
                },

                set(task, date, on) {
                    const list = this.checks[task] || []
                    this.checks[task] = on
                        ? (list.includes(date) ? list : [...list, date])
                        : list.filter(d => d !== date)
                },

                async toggle(task, date, url) {
                    const k = this.key(task, date)
                    if (this.busy[k]) return

                    this.busy[k] = true
                    this.error = ''

                    const was = this.isChecked(task, date)
                    this.set(task, date, !was)   // optimistic

                    try {
                        const res = await fetch(url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': token,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: JSON.stringify({ date }),
                        })

                        const data = await res.json().catch(() => ({}))

                        if (!res.ok) throw new Error(data.message || 'Gagal menyimpan.')

                        this.set(task, date, data.checked)
                    } catch (e) {
                        this.set(task, date, was)
                        this.error = e.message || 'Gagal menyimpan centang.'
                        setTimeout(() => { this.error = '' }, 4000)
                    } finally {
                        delete this.busy[k]
                    }
                },
            }
        }
    </script>

    {{-- Full list --}}
    <div class="card overflow-hidden">
        <div class="border-b border-slate-200 p-4 dark:border-white/5">
            <h2 class="text-base font-bold text-slate-800 dark:text-white">
                Semua Task ({{ number_format($list->total()) }})
            </h2>
            <p class="mt-0.5 text-xs text-slate-400">
                Termasuk task di luar rentang tanggal papan di atas.
            </p>
        </div>

        @if ($list->isEmpty())
            <x-empty-state icon="calendar" title="Belum ada task"
                           description="Buat task pertama untuk mulai menjadwalkan pekerjaan tim."/>
        @else
            <div class="divide-y divide-slate-100 dark:divide-white/5">
                @foreach ($list as $task)
                    <a href="{{ route('tasks.show', $task) }}"
                       class="flex flex-wrap items-center gap-3 p-4 transition hover:bg-slate-50 dark:hover:bg-white/[0.02]">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold text-slate-800 dark:text-white">{{ $task->title }}</p>
                            <p class="text-[11px] text-slate-400">
                                {{ $task->start_date->translatedFormat('d M Y') }} – {{ $task->due_date->translatedFormat('d M Y') }}
                                · {{ $task->durationDays() }} hari
                                · {{ $task->pic?->name ?? 'Tanpa PIC' }}
                                @if ($task->ticket) · Tiket {{ $task->ticket->number }} @endif
                            </p>
                        </div>

                        @if ($task->is_public)
                            <span class="badge-green"><x-icon name="eye" class="h-3 w-3"/> Publik</span>
                        @endif

                        @if ($task->isOverdue())
                            <span class="badge-red"><x-icon name="alert" class="h-3 w-3"/> Terlambat</span>
                        @endif

                        <span class="{{ $task->priority->badge() }}">{{ $task->priority->label() }}</span>
                        <span class="{{ $task->status->badge() }}">
                            <x-icon :name="$task->status->icon()" class="h-3 w-3"/> {{ $task->status->label() }}
                        </span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    <div class="mt-6">{{ $list->links() }}</div>

    {{-- New task --}}
    @if ($canManage)
        <x-modal name="new-task" title="Task Baru" max-width="2xl">
            <form method="POST" action="{{ route('tasks.store') }}" enctype="multipart/form-data" class="space-y-4">
                @csrf

                <div>
                    <label for="task_title" class="label">Judul <span class="text-rose-500">*</span></label>
                    <input id="task_title" name="title" value="{{ old('title') }}" class="input" required
                           placeholder="Contoh: Follow Up Mahasiswa Semester 2026.1">
                    @error('title') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="task_description" class="label">Deskripsi</label>
                    <textarea id="task_description" name="description" rows="3" class="input">{{ old('description') }}</textarea>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="start_date" class="label">Tanggal mulai <span class="text-rose-500">*</span></label>
                        <input id="start_date" name="start_date" type="date" class="input" required
                               value="{{ old('start_date', now()->toDateString()) }}">
                        @error('start_date') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="due_date" class="label">Tanggal selesai <span class="text-rose-500">*</span></label>
                        <input id="due_date" name="due_date" type="date" class="input" required
                               value="{{ old('due_date', now()->addWeek()->toDateString()) }}">
                        @error('due_date') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="task_status" class="label">Status</label>
                        <select id="task_status" name="status" class="input">
                            @foreach ($statuses as $value => $label)
                                <option value="{{ $value }}" @selected(old('status', 'planned') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="task_priority" class="label">Prioritas</label>
                        <select id="task_priority" name="priority" class="input">
                            @foreach ($priorities as $value => $label)
                                <option value="{{ $value }}" @selected(old('priority', 'normal') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="pic_id" class="label">PIC</label>
                        <select id="pic_id" name="pic_id" class="input">
                            <option value="">— Belum ditentukan —</option>
                            @foreach ($people as $person)
                                <option value="{{ $person->id }}" @selected(old('pic_id') == $person->id)>{{ $person->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="progress" class="label">Progress (%)</label>
                        <input id="progress" name="progress" type="number" min="0" max="100"
                               value="{{ old('progress', 0) }}" class="input">
                    </div>

                    <div class="sm:col-span-2">
                        <label for="task_attachment" class="label">Lampiran</label>
                        <input id="task_attachment" name="attachment" type="file" class="input">
                        @error('attachment') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                @if ($canPublish)
                    <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-3 dark:border-white/10">
                        <input type="checkbox" name="is_public" value="1" @checked(old('is_public'))
                               class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-white/20 dark:bg-ink-850">
                        <span>
                            <span class="block text-sm font-medium text-slate-700 dark:text-slate-200">Tampilkan di halaman publik</span>
                            <span class="block text-[11px] text-slate-400">
                                Hanya judul, deskripsi, tanggal, dan status yang ditampilkan.
                                Data mahasiswa tidak pernah muncul di halaman publik.
                            </span>
                        </span>
                    </label>
                @endif

                {{-- Buttons stay inside the form rather than in the modal's
                     footer slot: a slot renders outside this <form>, so the
                     submit button would not submit anything. --}}
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="open = false" class="btn-ghost">Batal</button>
                    <button class="btn-primary"><x-icon name="check" class="h-4 w-4"/> Simpan Task</button>
                </div>
            </form>
        </x-modal>
    @endif
</x-layouts.app>
