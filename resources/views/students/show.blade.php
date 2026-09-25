<x-layouts.app :title="$student->name()">
    <x-slot:header>
        <div class="flex min-w-0 items-center gap-3">
            <span class="avatar h-11 w-11 shrink-0">{{ $student->initial() }}</span>
            <div class="min-w-0">
                <h1 class="truncate text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">
                    {{ $student->name() }}
                </h1>
                <p class="font-mono text-xs text-slate-400">
                    {{ $student->nim }}@if ($student->nac) · {{ $student->nac }}@endif
                </p>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            @can(\App\Enums\Permission::CreateTickets->value)
                <a href="{{ route('tickets.create', ['student' => $student->id]) }}" class="btn-primary">
                    <x-icon name="plus" class="h-4 w-4"/> Buat Tiket
                </a>
            @endcan

            <a href="{{ route('students.index') }}" class="btn-outline">
                <x-icon name="chevron-left" class="h-4 w-4"/> Kembali
            </a>
        </div>
    </x-slot:header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Editable record --}}
            <form method="POST" action="{{ route('students.update', $student) }}" class="card p-5">
                @csrf
                @method('PUT')

                <h2 class="mb-4 text-base font-bold text-slate-800 dark:text-white">Data Mahasiswa</h2>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="nama" class="label">Nama</label>
                        <input id="nama" name="nama" value="{{ old('nama', $student->nama) }}" class="input">
                        @error('nama') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="nac" class="label">NAC</label>
                        <input id="nac" name="nac" value="{{ old('nac', $student->nac) }}" class="input">
                    </div>

                    <div>
                        <label for="no_hp_raw" class="label">Nomor HP / WhatsApp</label>
                        <input id="no_hp_raw" name="no_hp_raw" value="{{ old('no_hp_raw', $student->no_hp_raw) }}" class="input">
                        @if ($student->no_hp)
                            <p class="mt-1 text-[11px] text-slate-400">Tersimpan sebagai {{ $student->no_hp }}</p>
                        @endif
                    </div>

                    <div>
                        <label for="email" class="label">Email</label>
                        <input id="email" name="email" type="email" value="{{ old('email', $student->email) }}" class="input">
                        @error('email') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="program_studi" class="label">Program Studi</label>
                        <input id="program_studi" name="program_studi" value="{{ old('program_studi', $student->program_studi) }}" class="input">
                    </div>

                    <div>
                        <label for="fakultas" class="label">Fakultas</label>
                        <input id="fakultas" name="fakultas" value="{{ old('fakultas', $student->fakultas) }}" class="input">
                    </div>

                    <div>
                        <label for="kabupaten" class="label">Kabupaten/Kota</label>
                        <input id="kabupaten" name="kabupaten" value="{{ old('kabupaten', $student->kabupaten) }}" class="input">
                    </div>

                    <div>
                        <label for="kecamatan" class="label">Kecamatan</label>
                        <input id="kecamatan" name="kecamatan" value="{{ old('kecamatan', $student->kecamatan) }}" class="input">
                    </div>

                    <div>
                        <label for="kelurahan" class="label">Kelurahan/Desa</label>
                        <input id="kelurahan" name="kelurahan" value="{{ old('kelurahan', $student->kelurahan) }}" class="input">
                    </div>

                    <div>
                        <label for="semester_terakhir" class="label">Semester Terakhir</label>
                        <input id="semester_terakhir" name="semester_terakhir" value="{{ old('semester_terakhir', $student->semester_terakhir) }}" class="input">
                    </div>

                    <div>
                        <label for="kategori_masalah" class="label">Kondisi</label>
                        <select id="kategori_masalah" name="kategori_masalah" class="input">
                            @foreach ($conditions as $value => $label)
                                <option value="{{ $value }}" @selected($student->kategori_masalah->value === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="assignment_status" class="label">Status Assignment</label>
                        <select id="assignment_status" name="assignment_status" class="input">
                            @foreach ($assignments as $value => $label)
                                <option value="{{ $value }}" @selected($student->assignment_status->value === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="mt-4">
                    <label for="catatan" class="label">Catatan</label>
                    <textarea id="catatan" name="catatan" rows="3" class="input">{{ old('catatan', $student->catatan) }}</textarea>
                </div>

                @can(\App\Enums\Permission::ManageStudents->value)
                    <div class="mt-4 flex justify-end">
                        <button class="btn-primary"><x-icon name="check" class="h-4 w-4"/> Simpan</button>
                    </div>
                @endcan
            </form>

            {{-- Tickets raised for this student --}}
            <div class="card p-5">
                <h2 class="mb-4 text-base font-bold text-slate-800 dark:text-white">
                    Tiket ({{ $tickets->count() }})
                </h2>

                @forelse ($tickets as $ticket)
                    <a href="{{ route('tickets.show', $ticket) }}"
                       class="mb-2 flex items-center gap-3 rounded-xl border border-slate-200 p-3 transition hover:bg-slate-50 dark:border-white/10 dark:hover:bg-white/[0.02]">
                        <span class="font-mono text-xs font-bold text-brand-600 dark:text-brand-400">{{ $ticket->number }}</span>
                        <span class="min-w-0 flex-1 truncate text-sm text-slate-700 dark:text-slate-200">{{ $ticket->subject }}</span>
                        <span class="{{ $ticket->status->badge() }} shrink-0">{{ $ticket->status->label() }}</span>
                    </a>
                @empty
                    <p class="text-sm text-slate-400">Belum ada tiket untuk mahasiswa ini.</p>
                @endforelse
            </div>

            {{-- Columns the import kept but we do not have a field for. Shown
                 so nothing from the source file is invisible. --}}
            @if ($student->extra)
                <div class="card p-5">
                    <h2 class="mb-1 text-base font-bold text-slate-800 dark:text-white">Data Tambahan dari Berkas</h2>
                    <p class="mb-4 text-xs text-slate-400">
                        Kolom yang ada di berkas sumber tetapi belum dipetakan ke field khusus.
                    </p>

                    <dl class="grid gap-3 sm:grid-cols-2">
                        @foreach ($student->extra as $key => $value)
                            <div class="rounded-xl bg-slate-50 p-3 dark:bg-white/[0.03]">
                                <dt class="text-[11px] uppercase tracking-wide text-slate-400">{{ $key }}</dt>
                                <dd class="mt-0.5 text-sm text-slate-700 dark:text-slate-200">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endif
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            <div class="card p-5">
                <h2 class="mb-4 text-base font-bold text-slate-800 dark:text-white">Assignment</h2>

                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-400">Status</dt>
                        <dd>
                            <span class="{{ $student->assignment_status->badge() }}">
                                <x-icon :name="$student->assignment_status->icon()" class="h-3 w-3"/>
                                {{ $student->assignment_status->label() }}
                            </span>
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-400">Operator</dt>
                        <dd class="font-medium text-slate-700 dark:text-slate-200">{{ $student->assignee?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-400">Dibagikan oleh</dt>
                        <dd class="font-medium text-slate-700 dark:text-slate-200">{{ $student->assigner?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-400">Tanggal assign</dt>
                        <dd class="font-medium text-slate-700 dark:text-slate-200">
                            {{ $student->assigned_at?->translatedFormat('d M Y') ?? '—' }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="card p-5">
                <h2 class="mb-4 text-base font-bold text-slate-800 dark:text-white">Status Registrasi</h2>

                <dl class="space-y-3 text-sm">
                    @foreach ([
                        'Registrasi' => $student->status_registrasi,
                        'Pembayaran' => $student->status_pembayaran,
                        'Billing NAC' => $student->status_billing_nac,
                        'Registrasi Matkul' => $student->status_registrasi_matkul,
                    ] as $label => $value)
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-400">{{ $label }}</dt>
                            <dd class="font-medium text-slate-700 dark:text-slate-200">{{ $value ?: '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <div class="card p-5">
                <h2 class="mb-4 text-base font-bold text-slate-800 dark:text-white">Sumber Data</h2>

                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-400">Sumber</dt>
                        <dd class="font-medium text-slate-700 dark:text-slate-200">{{ $student->sumber_data ?: '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-400">Berkas import</dt>
                        <dd class="truncate font-medium text-slate-700 dark:text-slate-200">
                            {{ $student->import?->original_name ?? '—' }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-400">Ditambahkan</dt>
                        <dd class="font-medium text-slate-700 dark:text-slate-200">
                            {{ $student->created_at?->translatedFormat('d M Y') }}
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</x-layouts.app>
