<x-layouts.app title="Kategori Tiket">
    <x-slot:header>
        <div class="min-w-0">
            <h1 class="text-xl font-extrabold tracking-tight text-slate-800 dark:text-white">Kategori Tiket</h1>
            <p class="mt-0.5 text-sm text-slate-400">
                Dua tingkat: kategori dan sub kategori.
            </p>
        </div>

        <a href="{{ route('tickets.index') }}" class="btn-outline">
            <x-icon name="chevron-left" class="h-4 w-4"/> Daftar Tiket
        </a>
    </x-slot:header>

    <div class="grid gap-6 lg:grid-cols-3">
        <form method="POST" action="{{ route('tickets.categories.store') }}" class="card h-fit p-5">
            @csrf

            <h2 class="mb-4 text-base font-bold text-slate-800 dark:text-white">Tambah Kategori</h2>

            <div class="space-y-3">
                <div>
                    <label for="name" class="label">Nama <span class="text-rose-500">*</span></label>
                    <input id="name" name="name" value="{{ old('name') }}" class="input" required>
                    @error('name') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="parent_id" class="label">Induk</label>
                    <select id="parent_id" name="parent_id" class="input">
                        <option value="">— Kategori utama —</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected(old('parent_id') == $category->id)>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('parent_id') <p class="form-error">{{ $message }}</p> @enderror
                    <p class="mt-1 text-[11px] text-slate-400">Pilih induk untuk membuat sub kategori.</p>
                </div>

                <div>
                    <label for="description" class="label">Keterangan</label>
                    <input id="description" name="description" value="{{ old('description') }}" class="input">
                </div>

                <button class="btn-primary w-full"><x-icon name="plus" class="h-4 w-4"/> Tambah</button>
            </div>
        </form>

        <div class="space-y-3 lg:col-span-2">
            @forelse ($categories as $category)
                <div class="card p-4">
                    <form method="POST" action="{{ route('tickets.categories.update', $category) }}"
                          class="flex flex-wrap items-center gap-2">
                        @csrf
                        @method('PUT')

                        <input name="name" value="{{ $category->name }}"
                               class="input flex-1 !border-transparent !bg-transparent font-bold hover:!border-slate-200 dark:hover:!border-white/10">

                        <span class="badge-slate shrink-0">{{ $category->tickets_count }} tiket</span>

                        @unless ($category->is_active)
                            <span class="badge-amber shrink-0">Nonaktif</span>
                        @endunless

                        <input type="hidden" name="is_active" value="{{ $category->is_active ? 1 : 0 }}">

                        <button class="btn-outline btn-sm shrink-0" title="Simpan">
                            <x-icon name="check" class="h-3.5 w-3.5"/>
                        </button>
                    </form>

                    <div class="mt-3 space-y-1.5 border-l border-slate-200 pl-4 dark:border-white/10">
                        @forelse ($category->children as $sub)
                            <div class="flex flex-wrap items-center gap-2">
                                <form method="POST" action="{{ route('tickets.categories.update', $sub) }}"
                                      class="flex flex-1 flex-wrap items-center gap-2">
                                    @csrf
                                    @method('PUT')

                                    <input name="name" value="{{ $sub->name }}"
                                           class="input flex-1 !border-transparent !bg-transparent text-sm hover:!border-slate-200 dark:hover:!border-white/10">
                                    <input type="hidden" name="is_active" value="{{ $sub->is_active ? 1 : 0 }}">

                                    <span class="badge-slate shrink-0">{{ $sub->tickets_count }}</span>

                                    <button class="btn-outline btn-sm shrink-0" title="Simpan">
                                        <x-icon name="check" class="h-3.5 w-3.5"/>
                                    </button>
                                </form>

                                <form method="POST" action="{{ route('tickets.categories.destroy', $sub) }}"
                                      onsubmit="return confirm('Hapus sub kategori ini?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn-outline btn-sm text-rose-500" title="Hapus">
                                        <x-icon name="trash" class="h-3.5 w-3.5"/>
                                    </button>
                                </form>
                            </div>
                        @empty
                            <p class="text-xs text-slate-400">Belum ada sub kategori.</p>
                        @endforelse
                    </div>
                </div>
            @empty
                <div class="card">
                    <x-empty-state icon="hash" title="Belum ada kategori"
                                   description="Tambahkan kategori pertama lewat form di samping."/>
                </div>
            @endforelse
        </div>
    </div>
</x-layouts.app>
