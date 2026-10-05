<x-layouts.app title="Tiket Baru">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Tiket Baru</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Nomor tiket dibuat otomatis — perkiraan berikutnya <span class="font-mono">{{ $nextNumber }}</span>.
            </p>
        </div>

        <a href="{{ route('tickets.index') }}" class="btn-outline">
            <x-icon name="x" class="h-4 w-4"/> Batal
        </a>
    </x-slot:header>

    <form method="POST" action="{{ route('tickets.store') }}" enctype="multipart/form-data"
          x-data="categoryPicker(@js($categories->map(fn ($c) => ['id' => $c->id, 'children' => $c->children->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values()])->values()), @js(old('category_id')))">
        @csrf

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <div class="card p-5">
                    <h2 class="mb-4 text-base font-bold text-slate-800 dark:text-white">Informasi Tiket</h2>

                    <div class="space-y-4">
                        <div>
                            <label for="subject" class="label">Judul / Detail singkat <span class="text-rose-500">*</span></label>
                            <input id="subject" name="subject" value="{{ old('subject') }}" class="input" required
                                   placeholder="Contoh: Mahasiswa belum bisa melakukan registrasi">
                            @error('subject') <p class="form-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="source" class="label">Sumber <span class="text-rose-500">*</span></label>
                                <select id="source" name="source" class="input" required>
                                    @foreach ($sources as $sourceGroup => $groupItems)
                                        <optgroup label="{{ $sourceGroup }}">
                                        @foreach ($groupItems as $value => $label)
                                        <option value="{{ $value }}" @selected(old('source', $student ? 'import_mahasiswa' : 'manual') === $value)>
                                            {{ $label }}
                                        </option>
                                        @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                                @error('source') <p class="form-error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="number_format" class="label">Format ID</label>
                                <select id="number_format" name="number_format" class="input">
                                    @foreach ($numberFormats as $code => $label)
                                        <option value="{{ $code }}" @selected(old('number_format', $defaultFormat) === $code)>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('number_format') <p class="form-error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="priority" class="label">Prioritas <span class="text-rose-500">*</span></label>
                                <select id="priority" name="priority" class="input" required>
                                    @foreach ($priorities as $value => $label)
                                        <option value="{{ $value }}" @selected(old('priority', 'normal') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label for="flag" class="label">Flag</label>
                                <select id="flag" name="flag" class="input">
                                    @foreach ($flags as $value => $label)
                                        <option value="{{ $value }}" @selected(old('flag', 'netral') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-[11px] text-slate-400">
                                    Tandai <strong>Lead</strong> bila ada calon mahasiswa di balik tiket ini.
                                </p>
                            </div>

                            <div>
                                <label for="category_id" class="label">Kategori</label>
                                <select id="category_id" name="category_id" class="input" x-model="category">
                                    <option value="">— Tanpa kategori —</option>
                                    @foreach ($categories as $category)
                                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label for="sub_category_id" class="label">Sub Kategori</label>
                                <select id="sub_category_id" name="sub_category_id" class="input" x-bind:disabled="subs.length === 0">
                                    <option value="">— Tanpa sub kategori —</option>
                                    <template x-for="sub in subs" :key="sub.id">
                                        <option :value="sub.id" x-text="sub.name"></option>
                                    </template>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label for="description" class="label">Deskripsi</label>
                            <textarea id="description" name="description" rows="5" class="input"
                                      placeholder="Uraikan masalahnya…">{{ old('description') }}</textarea>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="due_at" class="label">Target selesai</label>
                                <input id="due_at" name="due_at" type="date" value="{{ old('due_at') }}" class="input">
                            </div>

                            <div>
                                <label for="attachment" class="label">Lampiran</label>
                                <input id="attachment" name="attachment" type="file" class="input">
                                @error('attachment') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card p-5">
                    <h2 class="mb-1 text-base font-bold text-slate-800 dark:text-white">Data Pemohon</h2>
                    <p class="mb-4 text-xs text-slate-400">
                        @if ($student)
                            Diambil dari data mahasiswa {{ $student->nim }}. Bisa disesuaikan bila perlu.
                        @else
                            Isi seperlunya. Tiket tetap bisa dibuat tanpa data ini.
                        @endif
                    </p>

                    @if ($student)
                        <input type="hidden" name="student_id" value="{{ $student->id }}">
                    @endif

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="requester_name" class="label">Nama</label>
                            <input id="requester_name" name="requester_name"
                                   value="{{ old('requester_name', $student?->nama) }}" class="input">
                        </div>

                        <div>
                            <label for="requester_nim" class="label">NIM</label>
                            <input id="requester_nim" name="requester_nim"
                                   value="{{ old('requester_nim', $student?->nim) }}" class="input">
                        </div>

                        <div>
                            <label for="requester_nac" class="label">NAC</label>
                            <input id="requester_nac" name="requester_nac"
                                   value="{{ old('requester_nac', $student?->nac) }}" class="input">
                        </div>

                        <div>
                            <label for="requester_phone" class="label">Nomor HP</label>
                            <input id="requester_phone" name="requester_phone"
                                   value="{{ old('requester_phone', $student?->no_hp_raw) }}" class="input">
                        </div>

                        <div class="sm:col-span-2">
                            <label for="requester_email" class="label">Email</label>
                            <input id="requester_email" name="requester_email" type="email"
                                   value="{{ old('requester_email', $student?->email) }}" class="input">
                            @error('requester_email') <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                @can(\App\Enums\Permission::AssignTickets->value)
                    <div class="card p-5">
                        <h2 class="mb-4 text-base font-bold text-slate-800 dark:text-white">Penugasan</h2>

                        <label for="assigned_to" class="label">Operator / Agent</label>
                        <select id="assigned_to" name="assigned_to" class="input">
                            <option value="">— Belum ditugaskan —</option>
                            <x-operator-options :operators="$operators" :selected="old('assigned_to')"/>
                        </select>

                        <p class="mt-2 text-[11px] text-slate-400">
                            Bisa dikosongkan sekarang dan ditugaskan nanti dari detail tiket.
                        </p>
                    </div>
                @endcan

                <div class="card p-5">
                    <button class="btn-primary w-full">
                        <x-icon name="check" class="h-4 w-4"/> Buat Tiket
                    </button>

                    <p class="mt-3 text-center text-[11px] text-slate-400">
                        Status awal: Open
                    </p>
                </div>
            </div>
        </div>
    </form>

    @push('scripts')
        <script>
            function categoryPicker(tree, initial) {
                return {
                    tree,
                    category: initial ?? '',

                    // Sub-categories follow the chosen parent; picking a new
                    // parent must not leave a sub-category from the old one
                    // selected, which would file the ticket under two branches.
                    get subs() {
                        const found = this.tree.find(c => String(c.id) === String(this.category));
                        return found ? found.children : [];
                    },
                };
            }
        </script>
    @endpush
</x-layouts.app>
