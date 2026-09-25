<x-layouts.app :title="$task->title">
    <x-slot:header>
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <span class="{{ $task->status->badge() }}">
                    <x-icon :name="$task->status->icon()" class="h-3 w-3"/> {{ $task->status->label() }}
                </span>
                <span class="{{ $task->priority->badge() }}">{{ $task->priority->label() }}</span>
                @if ($task->is_public)
                    <span class="badge-green"><x-icon name="eye" class="h-3 w-3"/> Publik</span>
                @endif
                @if ($task->isOverdue())
                    <span class="badge-red"><x-icon name="alert" class="h-3 w-3"/> Terlambat</span>
                @endif
            </div>
            <h1 class="mt-1 truncate text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">
                {{ $task->title }}
            </h1>
        </div>

        <a href="{{ route('tasks.index') }}" class="btn-outline">
            <x-icon name="chevron-left" class="h-4 w-4"/> Task Management
        </a>
    </x-slot:header>

    <div class="grid gap-6 lg:grid-cols-3">
        <form method="POST" action="{{ route('tasks.update', $task) }}" enctype="multipart/form-data"
              class="card p-5 lg:col-span-2">
            @csrf
            @method('PUT')

            <h2 class="mb-4 text-base font-bold text-slate-800 dark:text-white">Detail Task</h2>

            <div class="space-y-4">
                <div>
                    <label for="title" class="label">Judul</label>
                    <input id="title" name="title" value="{{ old('title', $task->title) }}" class="input" required>
                    @error('title') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="description" class="label">Deskripsi</label>
                    <textarea id="description" name="description" rows="5" class="input">{{ old('description', $task->description) }}</textarea>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="start_date" class="label">Tanggal mulai</label>
                        <input id="start_date" name="start_date" type="date" class="input" required
                               value="{{ old('start_date', $task->start_date->toDateString()) }}">
                        @error('start_date') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="due_date" class="label">Tanggal selesai</label>
                        <input id="due_date" name="due_date" type="date" class="input" required
                               value="{{ old('due_date', $task->due_date->toDateString()) }}">
                        @error('due_date') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="status" class="label">Status</label>
                        <select id="status" name="status" class="input">
                            @foreach ($statuses as $value => $label)
                                <option value="{{ $value }}" @selected($task->status->value === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="priority" class="label">Prioritas</label>
                        <select id="priority" name="priority" class="input">
                            @foreach ($priorities as $value => $label)
                                <option value="{{ $value }}" @selected($task->priority->value === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="pic_id" class="label">PIC</label>
                        <select id="pic_id" name="pic_id" class="input">
                            <option value="">— Belum ditentukan —</option>
                            @foreach ($people as $person)
                                <option value="{{ $person->id }}" @selected($task->pic_id === $person->id)>{{ $person->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="progress" class="label">Progress (%)</label>
                        <input id="progress" name="progress" type="number" min="0" max="100" class="input"
                               value="{{ old('progress', $task->progress) }}">
                    </div>

                    <div class="sm:col-span-2">
                        <label for="attachment" class="label">Lampiran</label>
                        <input id="attachment" name="attachment" type="file" class="input">
                        @error('attachment') <p class="form-error">{{ $message }}</p> @enderror

                        @if ($task->attachment_path)
                            <p class="mt-1.5 text-[11px] text-slate-400">
                                Saat ini:
                                <a href="{{ Storage::url($task->attachment_path) }}"
                                   target="_blank" rel="noopener noreferrer"
                                   class="text-brand-600 hover:underline dark:text-brand-400">
                                    {{ $task->attachment_name }}
                                </a>
                                — biarkan kosong untuk mempertahankannya.
                            </p>
                        @endif
                    </div>
                </div>

                <input type="hidden" name="ticket_id" value="{{ $task->ticket_id }}">

                @can(\App\Enums\Permission::PublishTasks->value)
                    <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-3 dark:border-white/10">
                        <input type="checkbox" name="is_public" value="1" @checked($task->is_public)
                               class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-white/20 dark:bg-ink-850">
                        <span>
                            <span class="block text-sm font-medium text-slate-700 dark:text-slate-200">Tampilkan di halaman publik</span>
                            <span class="block text-[11px] text-slate-400">
                                Yang tampil hanya judul, deskripsi, tanggal, dan status.
                            </span>
                        </span>
                    </label>
                @endcan
            </div>

            @can(\App\Enums\Permission::ManageTasks->value)
                <div class="mt-5 flex justify-end gap-2">
                    <button class="btn-primary"><x-icon name="check" class="h-4 w-4"/> Simpan</button>
                </div>
            @endcan
        </form>

        <div class="space-y-6">
            {{-- Daily activity.
                 The plan (start…due) and the record (these ticks) are separate
                 on purpose — see TaskPlanner. This panel is the record, newest
                 first, with a name against each day so a report of work is
                 answerable for. Before this, the planner wrote these rows and
                 the report counted them, but the task's own page — the one
                 place someone looks to ask "how did this go" — could not show
                 them at all. --}}
            <div class="card p-5">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h2 class="text-base font-bold text-slate-800 dark:text-white">
                        Aktivitas Harian
                        <span class="badge-slate ml-1">{{ $checks->count() }}</span>
                    </h2>

                    @can(\App\Enums\Permission::ManageTasks->value)
                        <a href="{{ route('tasks.index', [
                               'from' => $task->start_date->toDateString(),
                               'to' => $task->due_date->toDateString(),
                               'q' => $task->title,
                           ]) }}" class="shrink-0 text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">
                            Centang di papan →
                        </a>
                    @endcan
                </div>

                @if ($checks->isEmpty())
                    <p class="text-sm text-slate-400">
                        Belum ada hari yang dicentang. Tandai di Papan Rencana setiap kali task ini dikerjakan —
                        itu yang dihitung sebagai capaian, bukan rentang tanggalnya.
                    </p>
                @else
                    @php
                        $plannedDays = $task->durationDays();
                        $workedInPlan = $checks->filter(
                            fn ($c) => $c->date->betweenIncluded($task->start_date, $task->due_date)
                        )->count();
                        $outsidePlan = $checks->count() - $workedInPlan;
                    @endphp

                    <div class="mb-2 flex items-baseline gap-2">
                        <span class="text-2xl font-extrabold tracking-tight text-slate-800 dark:text-white">
                            {{ $workedInPlan }}
                        </span>
                        <span class="text-sm text-slate-400">dari {{ $plannedDays }} hari direncanakan</span>
                    </div>

                    <div class="mb-4 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                        <div class="h-full rounded-full bg-emerald-500"
                             style="width: {{ $plannedDays > 0 ? min(100, round($workedInPlan / $plannedDays * 100)) : 0 }}%"></div>
                    </div>

                    @if ($outsidePlan > 0)
                        {{-- Worth saying out loud rather than folding into the
                             total: work outside the plan usually means the
                             schedule was wrong, not the work. --}}
                        <p class="mb-3 text-[11px] text-amber-600 dark:text-amber-400">
                            <x-icon name="alert" class="mr-0.5 inline h-3.5 w-3.5"/>
                            {{ $outsidePlan }} hari dikerjakan di luar rentang yang dijadwalkan.
                        </p>
                    @endif

                    <ul class="max-h-72 space-y-1 overflow-y-auto pr-1">
                        @foreach ($checks as $check)
                            @php $inPlan = $check->date->betweenIncluded($task->start_date, $task->due_date); @endphp
                            <li class="flex items-start gap-2.5 rounded-lg px-2 py-1.5 {{ $inPlan ? '' : 'bg-amber-500/[0.07]' }}">
                                <span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded bg-emerald-500 text-[10px] font-bold leading-none text-white">✓</span>

                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                        {{ $check->date->translatedFormat('l, j F Y') }}
                                    </span>
                                    <span class="block text-[11px] text-slate-400">
                                        {{ $check->checker?->name ?? 'Pengguna dihapus' }}
                                        · dicatat {{ $check->created_at->diffForHumans() }}
                                        @unless ($inPlan)
                                            · <span class="text-amber-600 dark:text-amber-400">di luar rencana</span>
                                        @endunless
                                    </span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="card p-5">
                <h2 class="mb-4 text-base font-bold text-slate-800 dark:text-white">Ringkasan</h2>

                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-400">Durasi</dt>
                        <dd class="font-medium text-slate-700 dark:text-slate-200">{{ $task->durationDays() }} hari</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-400">Dibuat oleh</dt>
                        <dd class="font-medium text-slate-700 dark:text-slate-200">{{ $task->creator?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-400">Dibuat</dt>
                        <dd class="font-medium text-slate-700 dark:text-slate-200">
                            {{ $task->created_at->translatedFormat('d M Y') }}
                        </dd>
                    </div>
                    @if ($task->completed_at)
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-400">Diselesaikan</dt>
                            <dd class="font-medium text-slate-700 dark:text-slate-200">
                                {{ $task->completed_at->translatedFormat('d M Y') }}
                            </dd>
                        </div>
                    @endif
                </dl>

                <div class="mt-4">
                    <div class="mb-1 flex items-center justify-between text-xs">
                        <span class="text-slate-400">Progress</span>
                        <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $task->progress }}%</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-white/5">
                        <div class="h-full rounded-full {{ $task->status->barClass() }}" style="width: {{ $task->progress }}%"></div>
                    </div>
                </div>
            </div>

            {{-- The optional link to a ticket. The two stay separate entities;
                 this is a reference, not a merge. --}}
            @can(\App\Enums\Permission::ManageTasks->value)
                {{-- Its own form: sharing the edit form would inherit that
                     form's PUT method spoof and never reach the DELETE route. --}}
                <form method="POST" action="{{ route('tasks.destroy', $task) }}" class="card p-5"
                      onsubmit="return confirm('Hapus task ini?')">
                    @csrf
                    @method('DELETE')

                    <h2 class="mb-1 text-base font-bold text-slate-800 dark:text-white">Hapus Task</h2>
                    <p class="mb-3 text-xs text-slate-400">Task akan dihapus dari timeline dan halaman publik.</p>

                    <button class="btn-outline w-full text-rose-500">
                        <x-icon name="trash" class="h-4 w-4"/> Hapus Task
                    </button>
                </form>
            @endcan

            @if ($task->ticket)
                <div class="card p-5">
                    <h2 class="mb-3 text-base font-bold text-slate-800 dark:text-white">Tiket Terkait</h2>

                    <a href="{{ route('tickets.show', $task->ticket) }}"
                       class="flex items-center gap-3 rounded-xl border border-slate-200 p-3 transition hover:bg-slate-50 dark:border-white/10 dark:hover:bg-white/[0.02]">
                        <span class="font-mono text-xs font-bold text-brand-600 dark:text-brand-400">
                            {{ $task->ticket->number }}
                        </span>
                        <span class="min-w-0 flex-1 truncate text-sm text-slate-700 dark:text-slate-200">
                            {{ $task->ticket->subject }}
                        </span>
                    </a>
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
