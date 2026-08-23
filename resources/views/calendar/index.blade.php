<x-layouts.app title="Kalender">
    <x-slot:header>
        <div>
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Kalender</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Semua jadwal terbit dalam satu tampilan.
                @can(App\Enums\Permission::ManageCalendar->value)
                    <span class="hidden sm:inline">Seret kartu untuk mengubah jadwal.</span>
                @endcan
            </p>
        </div>
    </x-slot:header>

    @php
        $canNote = auth()->user()->hasPermission(App\Enums\Permission::ManageCalendarNotes);
    @endphp

    <div x-data="contentCalendar({
            eventsUrl: @js(route('calendar.events')),
            moveUrl: @js(route('calendar.move', ['content' => '__ID__'])),
            noteUrl: @js(url('calendar/notes')),
            canNote: @js($canNote),
            editable: @js(auth()->user()->hasPermission(App\Enums\Permission::ManageCalendar))
         })"
         x-init="mount()">

        {{-- Filters --}}
        <div class="card mb-5 flex flex-col gap-3 p-4 sm:flex-row sm:items-end">
            <div class="sm:w-56">
                <label for="status" class="label">Status</label>
                <select id="status" class="input" x-model="filters.status" @change="refresh()">
                    <option value="">Semua status</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div class="sm:w-56">
                <label for="creator" class="label">Creator</label>
                <select id="creator" class="input" x-model="filters.creator" @change="refresh()">
                    <option value="">Semua creator</option>
                    @foreach ($creators as $creator)
                        <option value="{{ $creator->id }}">{{ $creator->name }}</option>
                    @endforeach
                </select>
            </div>

            <button type="button" class="btn-outline sm:ml-auto"
                    @click="filters.status = ''; filters.creator = ''; refresh()">
                <x-icon name="refresh" class="h-4 w-4"/> Reset
            </button>
        </div>

        {{-- Legend --}}
        <div class="mb-4 flex flex-wrap gap-2">
            @foreach ($statuses as $status)
                <span class="inline-flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                    <span class="h-2.5 w-2.5 rounded-full" style="background: {{ $status->color() }}"></span>
                    {{ $status->label() }}
                </span>
            @endforeach
        </div>

        {{-- Calendar --}}
        <div class="card overflow-hidden p-4">
            <div x-ref="calendar" class="fc-app"></div>
        </div>

        @if ($canNote)
            <p class="mt-3 text-center text-xs text-slate-400">
                Klik tanggal untuk menambahkan catatan, reminder, atau deadline.
            </p>

            {{-- Note editor --}}
            <div x-show="noteOpen" x-cloak
                 class="fixed inset-0 z-[90] flex items-center justify-center p-4">

                <div x-show="noteOpen" x-transition.opacity @click="noteOpen = false"
                     class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm"></div>

                <div x-show="noteOpen"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 scale-95 translate-y-2"
                     x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                     class="card relative w-full max-w-lg p-6 shadow-2xl">

                    <div class="mb-4 flex items-start justify-between gap-4">
                        <p class="text-base font-semibold text-slate-800 dark:text-white"
                           x-text="note.id ? 'Ubah Catatan' : 'Tambah Catatan'"></p>
                        <button type="button" @click="noteOpen = false"
                                class="text-slate-400 transition hover:text-slate-600 dark:hover:text-slate-200">
                            <x-icon name="x" class="h-5 w-5"/>
                        </button>
                    </div>

                    <div>
                        <label class="label">Judul <span class="text-rose-500">*</span></label>
                        <input x-model="note.title" maxlength="150" class="input"
                               placeholder="Misal: Deadline konten Ramadan"
                               @keydown.enter="saveNote()">
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="label">Jenis</label>
                            <select x-model="note.type" class="input">
                                @foreach (App\Models\CalendarEvent::TYPES as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="label">Tanggal</label>
                            <input type="date" x-model="note.starts_at" class="input">
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="label">Catatan</label>
                        <textarea x-model="note.note" rows="3" maxlength="1000" class="input"
                                  placeholder="Detail tambahan (opsional)"></textarea>
                    </div>

                    <div class="mt-6 flex items-center justify-between gap-2">
                        <button type="button" x-show="note.id" @click="deleteNote()" :disabled="saving"
                                class="btn-danger btn-sm">
                            <x-icon name="trash" class="h-3.5 w-3.5"/> Hapus
                        </button>

                        <div class="ml-auto flex gap-2">
                            <button type="button" @click="noteOpen = false" class="btn-outline">Batal</button>
                            <button type="button" @click="saveNote()" :disabled="saving" class="btn-primary">
                                <x-icon name="check" class="h-4 w-4"/>
                                <span x-text="saving ? 'Menyimpan…' : 'Simpan'"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-layouts.app>

@push('head')
<style>
    /* Blend FullCalendar into the app's design tokens (incl. dark mode). */
    .fc-app { --fc-border-color: rgb(148 163 184 / 0.18); --fc-today-bg-color: rgb(139 92 246 / 0.07); }
    .fc-app .fc-toolbar-title { font-size: 1rem; font-weight: 700; }
    .fc-app .fc-button {
        background: transparent; border: 1px solid rgb(148 163 184 / 0.3);
        color: inherit; font-size: .78rem; font-weight: 600;
        border-radius: .6rem; padding: .35rem .7rem; text-transform: capitalize;
        box-shadow: none;
    }
    .fc-app .fc-button:hover { background: rgb(148 163 184 / 0.12); }
    .fc-app .fc-button-active,
    .fc-app .fc-button-primary:not(:disabled).fc-button-active {
        background: #7c3aed; border-color: #7c3aed; color: #fff;
    }
    .fc-app .fc-daygrid-day-number,
    .fc-app .fc-col-header-cell-cushion { color: inherit; text-decoration: none; font-size: .78rem; }
    .fc-app .fc-event { border-radius: .45rem; padding: 1px 4px; font-size: .72rem; cursor: pointer; }
    .fc-app .fc-event:hover { filter: brightness(1.08); }
    .fc-app .fc-list-event:hover td { background: rgb(148 163 184 / 0.1); }

    /* Toolbar has to stack on small screens or it overflows. */
    @media (max-width: 640px) {
        .fc-app .fc-toolbar { flex-direction: column; gap: .6rem; align-items: stretch; }
        .fc-app .fc-toolbar-chunk { display: flex; justify-content: center; flex-wrap: wrap; gap: .3rem; }
    }
</style>
@endpush
