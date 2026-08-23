@use('Illuminate\Support\Number')

<x-layouts.app title="Datasets">
    <x-slot:header>
        <div>
            <h1 class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-white">Datasets</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Upload JSON, then explore premium analytics per project.
            </p>
        </div>
    </x-slot:header>

    {{-- Upload --}}
    <div id="upload" class="card p-6">
        <form method="POST" action="{{ route('datasets.store') }}" enctype="multipart/form-data"
              x-data="{
                  file: null, dragging: false, busy: false,
                  pick(f) { if (f) { this.file = f; if (!this.$refs.name.value) this.$refs.name.value = f.name.replace(/\.[^.]+$/, ''); } }
              }"
              @submit="busy = true"
              class="grid gap-5 lg:grid-cols-[1.4fr_1fr]">
            @csrf

            {{-- Dropzone --}}
            <div>
                <label class="label">JSON file</label>
                <div @dragover.prevent="dragging = true"
                     @dragleave.prevent="dragging = false"
                     @drop.prevent="dragging = false; pick($event.dataTransfer.files[0]); $refs.input.files = $event.dataTransfer.files"
                     @click="$refs.input.click()"
                     :class="dragging ? 'border-brand-500 bg-brand-50/50 dark:bg-brand-500/5' : 'border-slate-200 dark:border-white/10'"
                     class="flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed px-6 py-10 text-center transition">
                    <span class="grid h-12 w-12 place-items-center rounded-2xl bg-brand-50 text-brand-600 dark:bg-brand-500/10">
                        <x-icon name="upload" class="w-6 h-6"/>
                    </span>
                    <p class="mt-3 text-sm font-semibold text-slate-700 dark:text-slate-200"
                       x-text="file ? file.name : 'Drop your .json file here'"></p>
                    <p class="mt-1 text-xs text-slate-400">
                        or click to browse · up to {{ Number::fileSize(config('datasets.max_upload_kb') * 1024) }} ·
                        array or <code class="rounded bg-slate-100 px-1 dark:bg-white/10">{ data: [...] }</code>
                    </p>
                    <input x-ref="input" type="file" name="file" accept=".json,application/json"
                           class="hidden" @change="pick($event.target.files[0])" required>
                </div>
                @error('file') <p class="mt-2 text-sm text-rose-500">{{ $message }}</p> @enderror
            </div>

            {{-- Meta --}}
            <div class="flex flex-col gap-4">
                <div>
                    <label class="label" for="name">Dataset name <span class="text-slate-400">(optional)</span></label>
                    <input x-ref="name" id="name" name="name" class="input"
                           placeholder="Auto from filename, e.g. 5000-hasil-ut-pkp" value="{{ old('name') }}">
                    @error('name') <p class="mt-2 text-sm text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div class="rounded-xl bg-slate-50 p-3 text-xs text-slate-500 dark:bg-white/5 dark:text-slate-400">
                    <x-icon name="shield" class="mb-1 inline w-4 h-4 text-brand-500"/>
                    Large files are streamed &amp; chunk-inserted on a queue — your browser never blocks.
                </div>
                <button class="btn-primary" :disabled="!file || busy">
                    <span x-show="!busy" class="inline-flex items-center gap-2">
                        <x-icon name="sparkles" class="w-4 h-4"/> Create dataset
                    </span>
                    <span x-show="busy">Uploading…</span>
                </button>
            </div>
        </form>
    </div>

    {{-- Filters --}}
    <form method="GET" class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center">
        <div class="relative flex-1">
            <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 w-4 h-4 -translate-y-1/2 text-slate-400"/>
            <input name="q" value="{{ request('q') }}" class="input pl-10" placeholder="Search datasets…">
        </div>
        <select name="status" class="input sm:w-48" onchange="this.form.submit()">
            <option value="">All statuses</option>
            @foreach (['completed','processing','pending','failed'] as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
        <button class="btn-outline">Filter</button>
    </form>

    {{-- Grid --}}
    @if ($datasets->count())
        <div class="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($datasets as $d)
                <div class="card-glow flex flex-col p-5">
                    <div class="flex items-start justify-between gap-3">
                        <a href="{{ route('datasets.show', $d) }}" class="min-w-0">
                            <h3 class="truncate text-base font-bold text-slate-800 dark:text-white hover:text-brand-600">
                                {{ $d->name }}
                            </h3>
                            <p class="mt-0.5 truncate text-xs text-slate-400">{{ $d->source_filename }}</p>
                        </a>
                        <x-status-badge :status="$d->status"/>
                    </div>

                    @if ($d->isProcessing())
                        <div class="mt-5" x-data="{ p: {{ $d->progress }} }"
                             x-init="
                                if ('{{ $d->status }}' !== 'completed') {
                                    let id = setInterval(async () => {
                                        let r = await (await fetch('{{ route('datasets.status', $d) }}')).json();
                                        p = r.progress;
                                        if (r.ready || r.status === 'failed') { clearInterval(id); location.reload(); }
                                    }, 2500);
                                }">
                            <div class="flex justify-between text-xs text-slate-400">
                                <span>Importing…</span><span x-text="p + '%'"></span>
                            </div>
                            <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-white/5">
                                <div class="h-full rounded-full bg-brand-500 transition-all duration-500"
                                     :style="`width:${p}%`"></div>
                            </div>
                        </div>
                    @else
                        <div class="mt-5 grid grid-cols-3 gap-2 text-center">
                            <div class="rounded-xl bg-slate-50 py-2.5 dark:bg-white/5">
                                <p class="text-lg font-bold text-slate-800 dark:text-white">{{ Number::abbreviate($d->total_rows) }}</p>
                                <p class="text-[11px] text-slate-400">Rows</p>
                            </div>
                            <div class="rounded-xl bg-slate-50 py-2.5 dark:bg-white/5">
                                <p class="text-lg font-bold text-emerald-600 dark:text-emerald-400">{{ Number::abbreviate($d->valid_count) }}</p>
                                <p class="text-[11px] text-slate-400">Valid</p>
                            </div>
                            <div class="rounded-xl bg-slate-50 py-2.5 dark:bg-white/5">
                                <p class="text-lg font-bold text-amber-600 dark:text-amber-400">{{ Number::abbreviate($d->qualified_count) }}</p>
                                <p class="text-[11px] text-slate-400">Qualified</p>
                            </div>
                        </div>
                    @endif

                    <div class="mt-5 flex items-center justify-between border-t border-slate-100 pt-4 dark:border-white/5">
                        <p class="text-xs text-slate-400">
                            {{ $d->creator?->name ?? 'System' }} · {{ $d->created_at->diffForHumans() }}
                        </p>
                        <div class="flex items-center gap-1">
                            <a href="{{ route('datasets.show', $d) }}" class="btn-ghost !px-2 !py-1.5" title="Open">
                                <x-icon name="eye" class="w-4 h-4"/>
                            </a>
                            <button type="button" @click="$dispatch('open-modal','rename-dataset-{{ $d->id }}')"
                                    class="btn-ghost !px-2 !py-1.5" title="Rename">
                                <x-icon name="edit" class="w-4 h-4"/>
                            </button>
                            <form method="POST" action="{{ route('datasets.destroy', $d) }}"
                                  onsubmit="return confirm('Delete “{{ $d->name }}” and all its data? This cannot be undone.')">
                                @csrf @method('DELETE')
                                <button class="btn-ghost !px-2 !py-1.5 text-rose-500" title="Delete">
                                    <x-icon name="trash" class="w-4 h-4"/>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6">{{ $datasets->links() }}</div>

        {{-- Rename modals — rendered at page level (outside the cards, whose
             hover transform would otherwise break the fixed-position modal) --}}
        @foreach ($datasets as $d)
            <x-modal name="rename-dataset-{{ $d->id }}" title="Rename dataset">
                <form method="POST" action="{{ route('datasets.update', $d) }}" class="space-y-4">
                    @csrf @method('PUT')
                    <div>
                        <label class="label">Dataset name</label>
                        <input name="name" class="input" required
                               value="{{ $d->name }}" maxlength="120">
                        <p class="mt-1.5 text-xs text-slate-400">
                            Only the display name changes — the dataset URL stays the same.
                        </p>
                    </div>
                    <div>
                        <label class="label">Description <span class="text-slate-400">(optional)</span></label>
                        <input name="description" class="input" maxlength="255"
                               value="{{ $d->description }}" placeholder="Short note about this dataset">
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="$dispatch('close-modal','rename-dataset-{{ $d->id }}')" class="btn-outline">Cancel</button>
                        <button class="btn-primary"><x-icon name="check" class="w-4 h-4"/> Save name</button>
                    </div>
                </form>
            </x-modal>
        @endforeach
    @else
        <div class="mt-6">
            <x-empty-state icon="layers" title="No datasets yet"
                           desc="Upload your first JSON file above to generate a premium analytics dashboard automatically." />
        </div>
    @endif
</x-layouts.app>
