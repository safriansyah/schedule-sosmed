@use('Illuminate\Support\Number')

<x-layouts.app :title="$dataset->name">
    <x-slot:header>
        <div class="min-w-0">
            <div class="flex items-center gap-2 text-xs text-slate-400">
                <a href="{{ route('datasets.index') }}" class="hover:text-brand-500">Datasets</a>
                <x-icon name="chevron-right" class="w-3 h-3"/>
                <span class="truncate">{{ $dataset->name }}</span>
            </div>
            <h1 class="mt-1 flex items-center gap-3 text-2xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                <span class="truncate">{{ $dataset->name }}</span>
                <x-status-badge :status="$dataset->status"/>
            </h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ $dataset->source_filename }} ·
                {{ Number::format($dataset->total_rows) }} rows ·
                imported {{ $dataset->imported_at?->diffForHumans() ?? '—' }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2" x-data="{ tools: false }">
            <div class="relative">
                <button @click="tools = !tools" @click.outside="tools = false" class="btn-outline">
                    <x-icon name="dots" class="w-4 h-4"/> Quick actions
                </button>
                <div x-show="tools" x-cloak x-transition
                     class="card absolute right-0 z-30 mt-2 w-60 p-2 shadow-xl">
                    <button @click="$dispatch('open-modal','rename-dataset'); tools=false" class="nav-link w-full">
                        <x-icon name="edit" class="w-4 h-4"/> Rename dataset
                    </button>
                    <button @click="$dispatch('open-modal','replace'); tools=false" class="nav-link w-full">
                        <x-icon name="upload" class="w-4 h-4"/> Replace file
                    </button>
                    <a href="{{ route('datasets.export', $dataset) }}?format=csv" class="nav-link">
                        <x-icon name="download" class="w-4 h-4"/> Export CSV
                    </a>
                    <a href="{{ route('datasets.export', $dataset) }}?format=json" class="nav-link">
                        <x-icon name="download" class="w-4 h-4"/> Export JSON
                    </a>
                    <div class="my-1 h-px bg-slate-100 dark:bg-white/5"></div>
                    <form method="POST" action="{{ route('datasets.destroy', $dataset) }}"
                          onsubmit="return confirm('Delete this dataset and ALL its data permanently?')">
                        @csrf @method('DELETE')
                        <button class="nav-link w-full text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-500/10">
                            <x-icon name="trash" class="w-4 h-4"/> Delete dataset
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </x-slot:header>

    @if (! $dataset->isReady())
        @include('datasets.partials.processing')
    @else
        <div x-data="{ tab: 'analytics' }">
            {{-- Tabs --}}
            <div class="mb-6 inline-flex rounded-xl bg-slate-100 p-1 dark:bg-white/5">
                <button @click="tab = 'analytics'"
                        :class="tab === 'analytics' ? 'bg-white shadow-sm text-slate-800 dark:bg-ink-800 dark:text-white' : 'text-slate-500'"
                        class="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition">
                    <x-icon name="pie" class="w-4 h-4"/> Analytics
                </button>
                <button @click="tab = 'table'"
                        :class="tab === 'table' ? 'bg-white shadow-sm text-slate-800 dark:bg-ink-800 dark:text-white' : 'text-slate-500'"
                        class="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition">
                    <x-icon name="database" class="w-4 h-4"/> Data table
                </button>
            </div>

            <div x-show="tab === 'analytics'" x-transition.opacity>
                @include('datasets.partials.analytics')
            </div>

            <div x-show="tab === 'table'" x-cloak x-transition.opacity>
                @include('datasets.partials.table')
            </div>
        </div>
    @endif

    {{-- Replace modal --}}
    <x-modal name="replace" title="Replace dataset data">
        <form method="POST" action="{{ route('datasets.replace', $dataset) }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Upload a new JSON file. All existing rows for this dataset will be replaced.
            </p>
            <input type="file" name="file" accept=".json,application/json" required
                   class="block w-full text-sm text-slate-500 file:mr-4 file:rounded-xl file:border-0 file:bg-brand-600 file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-white hover:file:bg-brand-500">
            @error('file') <p class="text-sm text-rose-500">{{ $message }}</p> @enderror
            <div class="flex justify-end gap-2">
                <button type="button" @click="$dispatch('close-modal','replace')" class="btn-outline">Batal</button>
                <button class="btn-primary"><x-icon name="upload" class="w-4 h-4"/> Replace &amp; process</button>
            </div>
        </form>
    </x-modal>

    {{-- Rename modal --}}
    <x-modal name="rename-dataset" title="Rename dataset">
        <form method="POST" action="{{ route('datasets.update', $dataset) }}" class="space-y-4">
            @csrf @method('PUT')
            <div>
                <label class="label">Dataset name</label>
                <input name="name" class="input" required autofocus
                       value="{{ $dataset->name }}" maxlength="120">
                <p class="mt-1.5 text-xs text-slate-400">
                    Only the display name changes — the dataset URL stays the same.
                </p>
            </div>
            <div>
                <label class="label">Description <span class="text-slate-400">(optional)</span></label>
                <input name="description" class="input" maxlength="255"
                       value="{{ $dataset->description }}" placeholder="Short note about this dataset">
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="$dispatch('close-modal','rename-dataset')" class="btn-outline">Batal</button>
                <button class="btn-primary"><x-icon name="check" class="w-4 h-4"/> Save name</button>
            </div>
        </form>
    </x-modal>
</x-layouts.app>
