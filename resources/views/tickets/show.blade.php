<x-layouts.app :title="$ticket->number">
    <x-slot:header>
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <span class="font-mono text-sm font-bold text-brand-600 dark:text-brand-400">{{ $ticket->number }}</span>
                <span class="{{ $ticket->status->badge() }}">
                    <x-icon :name="$ticket->status->icon()" class="h-3 w-3"/>
                    {{ $ticket->status->label() }}
                </span>
                <span class="{{ $ticket->priority->badge() }}">{{ $ticket->priority->label() }}</span>
                <span class="{{ $ticket->flag->badge() }}">
                    <x-icon :name="$ticket->flag->icon()" class="h-3 w-3"/> {{ $ticket->flag->label() }}
                </span>
            </div>
            <h1 class="mt-1 truncate text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">
                {{ $ticket->subject }}
            </h1>
        </div>

        <a href="{{ route('tickets.index') }}" class="btn-outline">
            <x-icon name="chevron-left" class="h-4 w-4"/> Daftar Tiket
        </a>
    </x-slot:header>

    @if ($ticket->isClosed())
        <div class="card mb-6 border-emerald-500/30 bg-emerald-500/[0.06] p-4">
            <div class="flex flex-wrap items-start gap-3">
                <x-icon name="check-circle" class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600 dark:text-emerald-400"/>
                <div class="min-w-0 flex-1">
                    <p class="font-semibold text-emerald-700 dark:text-emerald-400">
                        Tiket ditutup oleh {{ $ticket->closer?->name ?? 'sistem' }}
                        · {{ $ticket->closed_at?->translatedFormat('d M Y H:i') }}
                    </p>
                    <p class="mt-1 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">
                        {{ $ticket->resolution_note }}
                    </p>
                </div>

                @can(\App\Enums\Permission::CloseTickets->value)
                    <form method="POST" action="{{ route('tickets.reopen', $ticket) }}">
                        @csrf
                        <button class="btn-outline btn-sm"><x-icon name="rotate" class="h-3.5 w-3.5"/> Buka Kembali</button>
                    </form>
                @endcan
            </div>
        </div>
    @endif

    @error('follow_up') <div class="card mb-4 border-rose-500/30 p-3"><p class="form-error">{{ $message }}</p></div> @enderror
    @error('status') <div class="card mb-4 border-rose-500/30 p-3"><p class="form-error">{{ $message }}</p></div> @enderror
    @error('ticket') <div class="card mb-4 border-rose-500/30 p-3"><p class="form-error">{{ $message }}</p></div> @enderror

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Where the ticket came from --}}
            @if ($ticket->hasSourceReference())
                <div class="card p-5">
                    <div class="mb-3 flex items-center gap-2">
                        <span class="{{ $ticket->source->badge() }}">
                            <x-icon :name="$ticket->source->icon()" class="h-3 w-3"/>
                            {{ $ticket->source->label() }}
                        </span>
                        @if ($ticket->source_created_at)
                            <span class="text-xs text-slate-400">
                                {{ $ticket->source_created_at->translatedFormat('d M Y H:i') }}
                            </span>
                        @endif
                    </div>

                    @if ($ticket->source_username)
                        <p class="text-sm font-semibold text-slate-800 dark:text-white">&#64;{{ $ticket->source_username }}</p>
                    @endif

                    @if ($ticket->source_text)
                        <blockquote class="mt-2 rounded-xl border-l-4 border-brand-500/40 bg-slate-50 p-3 text-sm italic text-slate-600 dark:bg-white/[0.03] dark:text-slate-300">
                            “{{ $ticket->source_text }}”
                        </blockquote>
                    @endif

                    <div class="mt-3 flex flex-wrap gap-2">
                        @if ($ticket->source_post_url)
                            <a href="{{ $ticket->source_post_url }}" target="_blank" rel="noopener noreferrer"
                               class="btn-outline btn-sm">
                                <x-icon name="link" class="h-3.5 w-3.5"/> Lihat Postingan
                            </a>
                        @endif

                        @if ($ticket->interaction_id)
                            <a href="{{ route('interactions.show', $ticket->interaction_id) }}" class="btn-outline btn-sm">
                                <x-icon name="inbox" class="h-3.5 w-3.5"/> Buka Interaksi
                            </a>
                        @endif
                    </div>

                    @if ($ticket->source_external_id)
                        <p class="mt-3 border-t border-slate-100 pt-2 font-mono text-[11px] text-slate-400 dark:border-white/5">
                            Comment ID: {{ $ticket->source_external_id }}
                            @if ($ticket->source_sender_id) · Sender ID: {{ $ticket->source_sender_id }} @endif
                            @if ($ticket->source_post_id) · Post ID: {{ $ticket->source_post_id }} @endif
                        </p>
                    @endif
                </div>
            @endif

            {{-- From the Buku Tamu / Antrian: what the visitor filled in. --}}
            @if ($entry = $ticket->guestBookEntry)
                <div class="card p-5">
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <span class="{{ $ticket->source->badge() }}">
                            <x-icon :name="$ticket->source->icon()" class="h-3 w-3"/>
                            {{ $ticket->source->label() }}
                        </span>
                        <span class="text-xs text-slate-400">
                            Antrian <strong class="font-mono text-slate-600 dark:text-slate-300">{{ $entry->displayNumber() }}</strong>
                            · {{ $entry->queue_date->translatedFormat('d M Y') }}, {{ $entry->created_at->timezone('Asia/Jakarta')->format('H:i') }}
                        </span>
                    </div>

                    <div class="flex flex-wrap gap-4">
                        <dl class="grid min-w-0 flex-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                            <div><dt class="text-xs text-slate-400">Jenis kunjungan / layanan</dt><dd class="font-semibold text-slate-700 dark:text-slate-200">{{ $entry->serviceLabel() }}</dd></div>
                            <div><dt class="text-xs text-slate-400">Jenis kelamin</dt><dd class="text-slate-700 dark:text-slate-200">{{ $entry->gender->label() }}</dd></div>
                            <div>
                                <dt class="text-xs text-slate-400">No. WhatsApp</dt>
                                <dd>
                                    @if ($wa = \App\Support\PhoneNumber::waLink($entry->whatsapp))
                                        <a href="{{ $wa }}" target="_blank" rel="noopener" class="font-medium text-emerald-600 hover:underline">{{ \App\Support\PhoneNumber::pretty($entry->whatsapp) ?? $entry->whatsapp }}</a>
                                    @else
                                        {{ $entry->whatsapp }}
                                    @endif
                                </dd>
                            </div>
                            <div><dt class="text-xs text-slate-400">No. HP</dt><dd class="text-slate-700 dark:text-slate-200">{{ \App\Support\PhoneNumber::pretty($entry->phone) ?? $entry->phone }}</dd></div>
                        </dl>

                        @if ($entry->signature_path)
                            <div>
                                <p class="mb-1 text-xs text-slate-400">Paraf</p>
                                <img src="{{ route('guest-book.admin.signature', $entry) }}" alt="Paraf {{ $entry->name }}" loading="lazy"
                                     class="h-16 w-36 rounded-xl border border-slate-200 bg-white object-contain p-1 dark:border-white/10 dark:invert">
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Ticket information. Read-only to whoever lacks EditTickets
                 (Operator Follow Up): they work the case, they do not rewrite it. --}}
            @php $canEditInfo = ! $ticket->isClosed() && auth()->user()->can(\App\Enums\Permission::EditTickets->value); @endphp
            <form method="POST" action="{{ route('tickets.update', $ticket) }}" class="card p-5">
                @csrf
                @method('PUT')

                <h2 class="mb-4 flex items-center gap-2 text-base font-bold text-slate-800 dark:text-white">
                    Informasi Tiket
                    @if (! $canEditInfo && ! $ticket->isClosed())
                        <span class="badge-slate text-[11px] font-medium"><x-icon name="eye" class="h-3 w-3"/> Hanya lihat</span>
                    @endif
                </h2>

                <div class="space-y-4">
                    <div>
                        <label for="subject" class="label">Judul</label>
                        <input id="subject" name="subject" value="{{ old('subject', $ticket->subject) }}" class="input"
                               @disabled(! $canEditInfo)>
                        @error('subject') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="description" class="label">Deskripsi</label>
                        <textarea id="description" name="description" rows="4" class="input"
                                  @disabled(! $canEditInfo)>{{ old('description', $ticket->description) }}</textarea>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="category_id" class="label">Kategori</label>
                            <select id="category_id" name="category_id" class="input" @disabled(! $canEditInfo)>
                                <option value="">— Tanpa kategori —</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" @selected($ticket->category_id === $category->id)>
                                        {{ $category->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="sub_category_id" class="label">Sub Kategori</label>
                            <select id="sub_category_id" name="sub_category_id" class="input" @disabled(! $canEditInfo)>
                                <option value="">— Tanpa sub kategori —</option>
                                @foreach ($categories as $category)
                                    <optgroup label="{{ $category->name }}">
                                        @foreach ($category->children as $sub)
                                            <option value="{{ $sub->id }}" @selected($ticket->sub_category_id === $sub->id)>
                                                {{ $sub->name }}
                                            </option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="priority" class="label">Prioritas</label>
                            <select id="priority" name="priority" class="input" @disabled(! $canEditInfo)>
                                @foreach ($priorities as $value => $label)
                                    <option value="{{ $value }}" @selected($ticket->priority->value === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="due_at" class="label">Target selesai</label>
                            <input id="due_at" name="due_at" type="date" class="input"
                                   value="{{ old('due_at', $ticket->due_at?->format('Y-m-d')) }}"
                                   @disabled(! $canEditInfo)>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="requester_name" class="label">Nama</label>
                            <input id="requester_name" name="requester_name" class="input"
                                   value="{{ old('requester_name', $ticket->requester_name) }}" @disabled(! $canEditInfo)>
                        </div>
                        <div>
                            <label for="requester_nim" class="label">NIM</label>
                            <input id="requester_nim" name="requester_nim" class="input"
                                   value="{{ old('requester_nim', $ticket->requester_nim) }}" @disabled(! $canEditInfo)>
                        </div>
                        <div>
                            <label for="requester_nac" class="label">NAC</label>
                            <input id="requester_nac" name="requester_nac" class="input"
                                   value="{{ old('requester_nac', $ticket->requester_nac) }}" @disabled(! $canEditInfo)>
                        </div>
                        <div>
                            <label for="requester_phone" class="label">Nomor HP</label>
                            <input id="requester_phone" name="requester_phone" class="input"
                                   value="{{ old('requester_phone', $ticket->requester_phone) }}" @disabled(! $canEditInfo)>
                        </div>
                        <div class="sm:col-span-2">
                            <label for="requester_email" class="label">Email</label>
                            <input id="requester_email" name="requester_email" type="email" class="input"
                                   value="{{ old('requester_email', $ticket->requester_email) }}" @disabled(! $canEditInfo)>
                        </div>
                    </div>
                </div>

                @if ($ticket->isEditable())
                    @can(\App\Enums\Permission::EditTickets->value)
                        <div class="mt-4 flex justify-end">
                            <button class="btn-primary"><x-icon name="check" class="h-4 w-4"/> Simpan</button>
                        </div>
                    @endcan
                @endif
            </form>

            {{-- TiketDetail — the students this case turned out to be about.
                 Separate from the requester fields above: those are whoever
                 raised the case, these are the NIM(s) it concerns. --}}
            <div class="card p-5" x-data="{ adding: @js($errors->has('nim') || $errors->has('detail')) }">
                <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base font-bold text-slate-800 dark:text-white">
                        Data Mahasiswa ({{ $ticket->details->count() }})
                    </h2>

                    @if ($ticket->isEditable())
                        @can(\App\Enums\Permission::HandleTickets->value)
                            <button type="button" @click="adding = !adding" class="btn-outline btn-sm">
                                <x-icon name="plus" class="h-3.5 w-3.5"/> Tambah NIM
                            </button>
                        @endcan
                    @endif
                </div>

                <p class="mb-4 text-xs text-slate-400">
                    NIM yang terkait tiket ini. Kalau NIM sudah ada di data import, kolom lain terisi otomatis.
                </p>

                @error('detail') <p class="form-error mb-3">{{ $message }}</p> @enderror

                @forelse ($ticket->details as $detail)
                    <div class="mb-2 rounded-xl border border-slate-200 p-3 dark:border-white/10"
                         x-data="{ open: false }">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold text-slate-800 dark:text-white">
                                    {{ $detail->displayName() }}
                                </p>
                                <p class="font-mono text-[11px] text-slate-400">{{ $detail->nim }}</p>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                @if ($detail->student_id)
                                    <a href="{{ route('students.show', $detail->student_id) }}" class="badge-green">
                                        <x-icon name="link" class="h-3 w-3"/> Tertaut data import
                                    </a>
                                @else
                                    <span class="badge-amber" title="NIM ini belum ada di data mahasiswa hasil import">
                                        Belum ada di import
                                    </span>
                                @endif

                                @if ($ticket->isEditable())
                                    @can(\App\Enums\Permission::HandleTickets->value)
                                        <form method="POST" action="{{ route('tickets.details.destroy', [$ticket, $detail]) }}"
                                              onsubmit="return confirm('Hapus data mahasiswa ini dari tiket?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="text-slate-400 transition hover:text-rose-500" title="Hapus">
                                                <x-icon name="trash" class="h-4 w-4"/>
                                            </button>
                                        </form>
                                    @endcan
                                @endif
                            </div>
                        </div>

                        <dl class="mt-3 grid gap-2 text-xs sm:grid-cols-2">
                            <div>
                                <dt class="text-slate-400">Fakultas / Prodi</dt>
                                <dd class="text-slate-700 dark:text-slate-200">{{ $detail->academicLabel() }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-400">Wilayah</dt>
                                <dd class="text-slate-700 dark:text-slate-200">{{ $detail->regionLabel() }}</dd>
                            </div>
                            @if ($detail->shown('no_hp'))
                                <div>
                                    <dt class="text-slate-400">Nomor HP</dt>
                                    <dd class="text-slate-700 dark:text-slate-200">{{ $detail->shown('no_hp') }}</dd>
                                </div>
                            @endif
                            @if ($detail->shown('email'))
                                <div>
                                    <dt class="text-slate-400">Email</dt>
                                    <dd class="truncate text-slate-700 dark:text-slate-200">{{ $detail->shown('email') }}</dd>
                                </div>
                            @endif
                        </dl>

                        @php
                            $s = $detail->student;

                            // Everything the import knows about this person.
                            // Collapsed by default: an operator on a call wants
                            // the name and the number first, and the other
                            // twenty fields would bury them.
                            $fields = $s ? array_filter([
                                'NIM' => $s->nim,
                                'NAC' => $s->nac,
                                'Nama' => $s->nama,
                                'Fakultas' => $s->fakultas,
                                'Program Studi' => $s->program_studi,
                                'Semester Terakhir' => $s->semester_terakhir,
                                'Provinsi' => $s->provinsi,
                                'Kabupaten / Kota' => $s->kabupaten,
                                'Kecamatan' => $s->kecamatan,
                                'Kelurahan / Desa' => $s->kelurahan,
                                'Pokjar / SALUT' => $s->pokjar,
                                'Wilayah Ujian' => $s->wilayah_ujian,
                                'Alamat' => $s->alamat,
                                'Nomor HP' => $s->no_hp_raw ?: $s->no_hp,
                                'HP 2' => $s->hp2,
                                'Telepon' => $s->telp,
                                'Email' => $s->email,
                                'Email Alternatif' => $s->email_alternatif,
                                'SIPAS' => $s->sipas,
                                'Status Registrasi' => $s->status_registrasi,
                                'Status Pembayaran' => $s->status_pembayaran,
                                'Status Billing NAC' => $s->status_billing_nac,
                                'Status Registrasi Matkul' => $s->status_registrasi_matkul,
                                'Segmen' => $s->segmen,
                                'Petugas (dari file)' => $s->petugas_nama,
                            ], fn ($v) => filled($v)) : [];
                        @endphp

                        <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3 dark:border-white/5">
                            <button type="button" @click="open = !open" class="btn-outline btn-sm"
                                    @disabled(! $s)>
                                <x-icon name="id-card" class="h-3.5 w-3.5"/>
                                <span x-text="open ? 'Tutup Detail Mahasiswa' : 'Detail Mahasiswa'">Detail Mahasiswa</span>
                            </button>

                            @if ($s)
                                <a href="{{ route('students.show', $s) }}" class="btn-sm text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">
                                    Buka halaman mahasiswa
                                </a>
                            @else
                                <span class="text-[11px] text-slate-400">
                                    NIM ini belum ada di data import, jadi tidak ada detail yang bisa ditarik.
                                </span>
                            @endif
                        </div>

                        @if ($s)
                            <div x-show="open" x-cloak x-collapse class="mt-3">
                                <dl class="grid gap-x-4 gap-y-2 rounded-xl bg-slate-50 p-3 text-xs sm:grid-cols-2 dark:bg-white/[0.03]">
                                    @foreach ($fields as $label => $value)
                                        <div class="min-w-0">
                                            <dt class="text-slate-400">{{ $label }}</dt>
                                            <dd class="break-words text-slate-700 dark:text-slate-200">{{ $value }}</dd>
                                        </div>
                                    @endforeach
                                </dl>

                                @if ($s->kategori_masalah)
                                    <p class="mt-2 flex items-center gap-2 text-xs">
                                        <span class="text-slate-400">Kondisi:</span>
                                        <span class="{{ $s->kategori_masalah->badge() }}">{{ $s->kategori_masalah->label() }}</span>
                                    </p>
                                @endif

                                @if ($s->catatan)
                                    <p class="mt-2 whitespace-pre-line rounded-lg bg-white p-2 text-xs text-slate-600 dark:bg-ink-850 dark:text-slate-300">
                                        {{ $s->catatan }}
                                    </p>
                                @endif
                            </div>
                        @endif

                        @if ($detail->catatan)
                            <p class="mt-2 whitespace-pre-line rounded-lg bg-slate-50 p-2 text-xs text-slate-600 dark:bg-white/[0.03] dark:text-slate-300">
                                {{ $detail->catatan }}
                            </p>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-slate-400">
                        Belum ada NIM yang dicatat. Tambahkan setelah mengetahui identitas mahasiswanya.
                    </p>
                @endforelse

                @if ($ticket->isEditable())
                    @can(\App\Enums\Permission::HandleTickets->value)
                        <form method="POST" action="{{ route('tickets.details.store', $ticket) }}"
                              x-show="adding" x-cloak x-collapse
                              class="mt-4 border-t border-slate-100 pt-4 dark:border-white/5">
                            @csrf

                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label for="nim" class="label">NIM <span class="text-rose-500">*</span></label>
                                    <input id="nim" name="nim" value="{{ old('nim') }}" class="input" required
                                           placeholder="010123456">
                                    @error('nim') <p class="form-error">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label for="detail_nama" class="label">Nama</label>
                                    <input id="detail_nama" name="nama" value="{{ old('nama') }}" class="input">
                                </div>

                                <div>
                                    <label for="fakultas" class="label">Fakultas</label>
                                    <input id="fakultas" name="fakultas" value="{{ old('fakultas') }}" class="input">
                                </div>

                                <div>
                                    <label for="prodi" class="label">Program Studi</label>
                                    <input id="prodi" name="prodi" value="{{ old('prodi') }}" class="input">
                                </div>

                                <div>
                                    <label for="provinsi" class="label">Provinsi</label>
                                    <input id="provinsi" name="provinsi" value="{{ old('provinsi') }}" class="input">
                                </div>

                                <div>
                                    <label for="detail_kabupaten" class="label">Kabupaten/Kota</label>
                                    <input id="detail_kabupaten" name="kabupaten" value="{{ old('kabupaten') }}" class="input">
                                </div>

                                <div>
                                    <label for="detail_kecamatan" class="label">Kecamatan</label>
                                    <input id="detail_kecamatan" name="kecamatan" value="{{ old('kecamatan') }}" class="input">
                                </div>

                                <div>
                                    <label for="detail_kelurahan" class="label">Kelurahan/Desa</label>
                                    <input id="detail_kelurahan" name="kelurahan" value="{{ old('kelurahan') }}" class="input">
                                </div>

                                <div>
                                    <label for="detail_no_hp" class="label">Nomor HP</label>
                                    <input id="detail_no_hp" name="no_hp" value="{{ old('no_hp') }}" class="input">
                                </div>

                                <div>
                                    <label for="detail_email" class="label">Email</label>
                                    <input id="detail_email" name="email" type="email" value="{{ old('email') }}" class="input">
                                    @error('email') <p class="form-error">{{ $message }}</p> @enderror
                                </div>

                                <div class="sm:col-span-2">
                                    <label for="detail_catatan" class="label">Catatan</label>
                                    <textarea id="detail_catatan" name="catatan" rows="2" class="input">{{ old('catatan') }}</textarea>
                                </div>
                            </div>

                            <div class="mt-3 flex justify-end gap-2">
                                <button type="button" @click="adding = false" class="btn-ghost">Batal</button>
                                <button class="btn-primary">
                                    <x-icon name="check" class="h-4 w-4"/> Simpan Data Mahasiswa
                                </button>
                            </div>
                        </form>
                    @endcan
                @endif
            </div>

            {{-- Follow-up history. Append-only: a new entry never replaces an
                 old one, which is why this is a list and not a single field. --}}
            <div class="card p-5">
                <h2 class="mb-1 text-base font-bold text-slate-800 dark:text-white">
                    Riwayat Follow Up ({{ $ticket->followUps->count() }})
                </h2>
                <p class="mb-4 text-xs text-slate-400">
                    Setiap tindak lanjut tersimpan berurutan dan tidak pernah menimpa yang sebelumnya.
                </p>

                @forelse ($ticket->followUps as $index => $followUp)
                    <div class="relative pl-8 {{ $loop->last ? '' : 'pb-6' }}">
                        @unless ($loop->last)
                            <span class="absolute left-[11px] top-7 h-full w-px bg-slate-200 dark:bg-white/10"></span>
                        @endunless

                        <span class="absolute left-0 top-1 grid h-6 w-6 place-items-center rounded-full bg-brand-500/15 text-[10px] font-bold text-brand-600 dark:text-brand-300">
                            {{ $index + 1 }}
                        </span>

                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-sm font-semibold text-slate-800 dark:text-white">
                                Follow Up #{{ $index + 1 }}
                            </p>
                            <span class="text-xs text-slate-400">
                                {{ $followUp->created_at->translatedFormat('d M Y H:i') }}
                            </span>
                            @if ($followUp->status_after)
                                <span class="{{ $followUp->status_after->badge() }}">
                                    <x-icon :name="$followUp->status_after->icon()" class="h-3 w-3"/>
                                    {{ $followUp->status_after->label() }}
                                </span>
                            @endif

                            @if ($followUp->outcome)
                                <span class="{{ $followUp->outcome->badge() }}">{{ $followUp->outcome->label() }}</span>
                            @endif
                        </div>

                        <p class="mt-0.5 text-xs text-slate-400">
                            {{ $followUp->user?->name ?? 'Pengguna dihapus' }}
                            @if ($followUp->role_at_time) · {{ $followUp->role_at_time }} @endif
                            @if ($followUp->channel_used) · via {{ $followUp->channel_used }} @endif
                            @if ($followUp->action) · {{ $followUp->action->label() }} @endif
                        </p>

                        @if ($followUp->response_text)
                            <p class="mt-2 whitespace-pre-line rounded-xl bg-slate-50 p-3 text-sm text-slate-600 dark:bg-white/[0.03] dark:text-slate-300">
                                {{ $followUp->response_text }}
                            </p>
                        @endif

                        @if ($pairs = $followUp->additionalPairs())
                            <div class="mt-2">
                                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Data tambahan</p>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($pairs as $key => $value)
                                        <span class="badge-cyan">
                                            <span class="font-normal opacity-70">{{ $key }}:</span> {{ $value }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div class="mt-2 flex flex-wrap gap-3 text-[11px] text-slate-400">
                            @if ($followUp->next_action_at)
                                <span>
                                    <x-icon name="clock" class="inline h-3 w-3"/>
                                    Tindak lanjut berikutnya {{ $followUp->next_action_at->translatedFormat('d M Y') }}
                                </span>
                            @endif

                            @if ($followUp->hasAttachment())
                                <a href="{{ \Illuminate\Support\Facades\Storage::url($followUp->attachment_path) }}"
                                   target="_blank" rel="noopener noreferrer" class="text-brand-600 hover:underline dark:text-brand-400">
                                    <x-icon name="link" class="inline h-3 w-3"/> {{ $followUp->attachment_name }}
                                </a>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-400">Belum ada follow up.</p>
                @endforelse
            </div>

            {{-- New follow-up --}}
            @if ($ticket->isEditable())
                @can(\App\Enums\Permission::HandleTickets->value)
                    <form method="POST" action="{{ route('tickets.followUp', $ticket) }}"
                          enctype="multipart/form-data" class="card p-5"
                          x-data="{ extras: [{ key: '', value: '' }] }">
                        @csrf

                        <h2 class="mb-4 text-base font-bold text-slate-800 dark:text-white">Tambah Follow Up</h2>

                        <div class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <label for="action" class="label">Tindakan <span class="text-rose-500">*</span></label>
                                <select id="action" name="action" class="input" required>
                                    @foreach ($actions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('action') <p class="form-error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="channel_used" class="label">Metode</label>
                                <select id="channel_used" name="channel_used" class="input">
                                    <option value="">—</option>
                                    @foreach (['wa' => 'WhatsApp', 'telepon' => 'Telepon', 'dm' => 'Direct Message', 'email' => 'Email', 'tatap_muka' => 'Tatap Muka'] as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label for="outcome" class="label">Hasil</label>
                                <select id="outcome" name="outcome" class="input">
                                    <option value="">—</option>
                                    @foreach ($outcomes as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="mt-4">
                            <label for="response_text" class="label">Catatan</label>
                            <textarea id="response_text" name="response_text" rows="3" class="input"
                                      placeholder="Contoh: Sudah menghubungi mahasiswa, akan membayar minggu depan."></textarea>
                        </div>

                        {{-- Additional data: whatever the operator learned. Known
                             keys (no_hp, email, nama, nim, nac) also update the
                             ticket itself, with the old value kept in the log. --}}
                        <div class="mt-4">
                            <label class="label">Data Tambahan</label>
                            <p class="mb-2 -mt-1 text-[11px] text-slate-400">
                                Informasi baru dari percakapan. Kata kunci <span class="font-mono">no_hp</span>,
                                <span class="font-mono">email</span>, <span class="font-mono">nama</span>,
                                <span class="font-mono">nim</span>, <span class="font-mono">nac</span>
                                ikut memperbarui data tiket — data lama tetap tersimpan di log.
                            </p>

                            <template x-for="(extra, index) in extras" :key="index">
                                <div class="mb-2 flex flex-wrap gap-2 sm:flex-nowrap">
                                    <input type="text" :name="`extra_key[${index}]`" x-model="extra.key"
                                           class="input w-full sm:w-56" placeholder="Label (mis. no_hp)">
                                    <input type="text" :name="`extra_value[${index}]`" x-model="extra.value"
                                           class="input min-w-0 flex-1" placeholder="Nilai">
                                    <button type="button" @click="extras.splice(index, 1)" x-show="extras.length > 1"
                                            class="btn-outline btn-sm shrink-0">
                                        <x-icon name="x" class="h-3.5 w-3.5"/>
                                    </button>
                                </div>
                            </template>

                            <button type="button" @click="extras.push({ key: '', value: '' })" class="btn-outline btn-sm">
                                <x-icon name="plus" class="h-3.5 w-3.5"/> Tambah Baris
                            </button>
                        </div>

                        <div class="mt-4 grid gap-4 sm:grid-cols-3">
                            <div>
                                <label for="status" class="label">Status follow up</label>
                                <select id="status" name="status" class="input">
                                    @foreach ($followUpStatuses as $value => $label)
                                        <option value="{{ $value }}" @selected($value === 'on_proses')>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-[11px] text-slate-400">
                                    Close lewat tombol Tutup Tiket, agar hasil penyelesaian ikut tersimpan.
                                </p>
                            </div>

                            <div>
                                <label for="next_action_at" class="label">Tindak lanjut berikutnya</label>
                                <input id="next_action_at" name="next_action_at" type="date" class="input">
                            </div>

                            <div>
                                <label for="attachment" class="label">Lampiran</label>
                                <input id="attachment" name="attachment" type="file" class="input">
                                @error('attachment') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div class="mt-4 flex justify-end">
                            <button class="btn-primary"><x-icon name="plus" class="h-4 w-4"/> Simpan Follow Up</button>
                        </div>
                    </form>
                @endcan
            @endif

            {{-- Audit trail for this ticket --}}
            <div class="card p-5">
                <h2 class="mb-4 text-base font-bold text-slate-800 dark:text-white">Log Aktivitas</h2>

                @forelse ($activities as $activity)
                    <div class="flex items-start gap-3 border-b border-slate-100 py-2 last:border-0 dark:border-white/5">
                        <x-icon :name="$activity->icon()" class="mt-0.5 h-4 w-4 shrink-0 text-slate-400"/>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-slate-600 dark:text-slate-300">{{ $activity->description }}</p>
                            <p class="text-[11px] text-slate-400">
                                {{ $activity->user?->name ?? 'Sistem' }} · {{ $activity->created_at->translatedFormat('d M Y H:i') }}
                            </p>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-400">Belum ada aktivitas tercatat.</p>
                @endforelse
            </div>
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            @can(\App\Enums\Permission::AssignTickets->value)
                <form method="POST" action="{{ route('tickets.assign', $ticket) }}" class="card p-5">
                    @csrf
                    <h2 class="mb-4 text-base font-bold text-slate-800 dark:text-white">Penugasan</h2>

                    <label for="assigned_to" class="label">Operator / Agent</label>
                    <select id="assigned_to" name="assigned_to" class="input" @disabled($ticket->isClosed())>
                        <option value="">— Belum ditugaskan —</option>
                        <x-operator-options :operators="$operators" :selected="$ticket->assigned_to"/>
                    </select>

                    <div class="mt-3">
                        <label for="note" class="label">Catatan penugasan</label>
                        <input id="note" name="note" class="input" placeholder="Opsional" @disabled($ticket->isClosed())>
                    </div>

                    @error('assign') <p class="form-error">{{ $message }}</p> @enderror

                    @unless ($ticket->isClosed())
                        <button class="btn-primary mt-3 w-full">
                            <x-icon name="user-plus" class="h-4 w-4"/> Simpan Penugasan
                        </button>
                    @endunless

                    @if ($ticket->assignments->isNotEmpty())
                        <div class="mt-4 border-t border-slate-100 pt-3 dark:border-white/5">
                            <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Riwayat</p>
                            @foreach ($ticket->assignments as $assignment)
                                <p class="text-[11px] text-slate-400">
                                    {{ $assignment->describe() }}
                                    · {{ $assignment->created_at->translatedFormat('d M H:i') }}
                                </p>
                            @endforeach
                        </div>
                    @endif
                </form>
            @endcan

            @if ($ticket->isEditable())
                @can(\App\Enums\Permission::EditTickets->value)
                    <form method="POST" action="{{ route('tickets.status', $ticket) }}" class="card p-5">
                        @csrf
                        <h2 class="mb-4 text-base font-bold text-slate-800 dark:text-white">Ubah Status</h2>

                        <select name="status" class="input">
                            @foreach ($statuses as $value => $label)
                                @continue($value === 'closed')
                                <option value="{{ $value }}" @selected($ticket->status->value === $value)>{{ $label }}</option>
                            @endforeach
                        </select>

                        <button class="btn-outline mt-3 w-full">
                            <x-icon name="refresh" class="h-4 w-4"/> Perbarui Status
                        </button>
                    </form>
                @endcan

                @can(\App\Enums\Permission::CloseTickets->value)
                    <form method="POST" action="{{ route('tickets.close', $ticket) }}" class="card p-5">
                        @csrf
                        <h2 class="mb-1 text-base font-bold text-slate-800 dark:text-white">Tutup Tiket</h2>
                        <p class="mb-3 text-xs text-slate-400">
                            Tiket tetap tersimpan lengkap dengan riwayatnya.
                        </p>

                        <label for="resolution_note" class="label">Hasil penyelesaian <span class="text-rose-500">*</span></label>
                        <textarea id="resolution_note" name="resolution_note" rows="3" class="input" required
                                  placeholder="Contoh: Mahasiswa sudah melakukan registrasi dan pembayaran."></textarea>
                        @error('resolution_note') <p class="form-error">{{ $message }}</p> @enderror

                        <button class="btn-success mt-3 w-full">
                            <x-icon name="check-circle" class="h-4 w-4"/> Tutup Tiket
                        </button>
                    </form>
                @endcan
            @endif

            @can(\App\Enums\Permission::EditTickets->value)
                <form method="POST" action="{{ route('tickets.flag', $ticket) }}" class="card p-5">
                    @csrf
                    <h2 class="mb-1 text-base font-bold text-slate-800 dark:text-white">Flag</h2>
                    <p class="mb-3 text-xs text-slate-400">
                        Terpisah dari status: status menunjukkan posisi tiket, flag menunjukkan
                        ada atau tidaknya calon mahasiswa di balik tiket ini.
                    </p>

                    <select name="flag" class="input">
                        @foreach ($flags as $value => $label)
                            <option value="{{ $value }}" @selected($ticket->flag->value === $value)>{{ $label }}</option>
                        @endforeach
                    </select>

                    @error('flag') <p class="form-error">{{ $message }}</p> @enderror

                    <button class="btn-outline mt-3 w-full">
                        <x-icon name="target" class="h-4 w-4"/> Simpan Flag
                    </button>
                </form>
            @endcan

            <div class="card p-5">
                <h2 class="mb-4 text-base font-bold text-slate-800 dark:text-white">Ringkasan</h2>

                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-400">Dibuat oleh</dt>
                        <dd class="font-medium text-slate-700 dark:text-slate-200">{{ $ticket->creator?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-400">Dibuat</dt>
                        <dd class="font-medium text-slate-700 dark:text-slate-200">
                            {{ $ticket->created_at->translatedFormat('d M Y H:i') }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-400">Follow up</dt>
                        <dd class="font-medium text-slate-700 dark:text-slate-200">{{ $ticket->follow_up_count }}×</dd>
                    </div>
                    @if ($ticket->hasAttachment())
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-400">Lampiran</dt>
                            <dd class="min-w-0">
                                <a href="{{ \Illuminate\Support\Facades\Storage::url($ticket->attachment_path) }}"
                                   target="_blank" rel="noopener noreferrer"
                                   class="block truncate font-medium text-brand-600 hover:underline dark:text-brand-400">
                                    {{ $ticket->attachment_name }}
                                </a>
                            </dd>
                        </div>
                    @endif
                    @if ($ticket->last_follow_up_at)
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-400">Terakhir</dt>
                            <dd class="font-medium text-slate-700 dark:text-slate-200">
                                {{ $ticket->last_follow_up_at->diffForHumans() }}
                            </dd>
                        </div>
                    @endif
                    @if ($ticket->student)
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-400">Mahasiswa</dt>
                            <dd>
                                <a href="{{ route('students.show', $ticket->student) }}"
                                   class="font-medium text-brand-600 hover:underline dark:text-brand-400">
                                    {{ $ticket->student->nim }}
                                </a>
                            </dd>
                        </div>
                    @endif
                    @if ($ticket->contact)
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-400">Kontak</dt>
                            <dd>
                                <a href="{{ route('contacts.show', $ticket->contact) }}"
                                   class="font-medium text-brand-600 hover:underline dark:text-brand-400">
                                    {{ $ticket->contact->code }}
                                </a>
                            </dd>
                        </div>
                    @endif
                </dl>
            </div>
        </div>
    </div>
</x-layouts.app>
