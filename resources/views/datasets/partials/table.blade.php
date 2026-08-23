@php
    $platforms = collect($analytics['platform_distribution'] ?? [])->pluck('label')->all();
    $perPageOptions = config('datasets.per_page_options');
@endphp

<div
    x-data="datasetTable({
        url: @js(route('datasets.table', $dataset)),
        exportUrl: @js(route('datasets.export', $dataset)),
        storeUrl: @js(route('datasets.items.store', $dataset)),
        rowUrl: @js(url('datasets/'.$dataset->slug.'/items')),
        bulkUrl: @js(route('datasets.items.bulkDestroy', $dataset)),
        perPage: {{ (int) config('datasets.per_page') }},
    })"
    x-init="load()"
    class="card overflow-hidden"
>
    {{-- Toolbar --}}
    <div class="flex flex-col gap-3 border-b border-slate-100 p-4 dark:border-white/5 lg:flex-row lg:items-center">
        <div class="relative flex-1">
            <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 w-4 h-4 -translate-y-1/2 text-slate-400"/>
            <input x-model="filters.search" @input.debounce.350ms="resetAndLoad()"
                   class="input pl-10" placeholder="Search name, username, ID…">
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <select x-model="filters.platform" @change="resetAndLoad()" class="input !w-auto">
                <option value="">All platforms</option>
                @foreach ($platforms as $p)
                    <option value="{{ $p }}">{{ ucfirst($p) }}</option>
                @endforeach
            </select>

            <select x-model="filters.valid" @change="resetAndLoad()" class="input !w-auto">
                <option value="">Valid: any</option>
                <option value="1">Valid</option>
                <option value="0">Invalid</option>
            </select>

            <select x-model="filters.qualified" @change="resetAndLoad()" class="input !w-auto">
                <option value="">Qualified: any</option>
                <option value="1">Qualified</option>
                <option value="0">Not qualified</option>
            </select>

            <select x-model="filters.bucket" @change="resetAndLoad()" class="input !w-auto">
                <option value="">Followers: all</option>
                @foreach (config('datasets.follower_buckets') as $b)
                    <option value="{{ $b['label'] }}">{{ $b['label'] }}</option>
                @endforeach
            </select>

            <button @click="resetFilters()" class="btn-ghost !px-2.5" title="Clear filters">
                <x-icon name="x" class="w-4 h-4"/>
            </button>
        </div>
    </div>

    {{-- Actions bar --}}
    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm">
        <div class="flex items-center gap-3">
            <span class="text-slate-500 dark:text-slate-400">
                <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="meta.total ?? 0"></span> rows
            </span>
            <template x-if="selected.length">
                <button @click="bulkDelete()" class="btn-danger !py-1.5 !px-3">
                    <x-icon name="trash" class="w-4 h-4"/>
                    Delete (<span x-text="selected.length"></span>)
                </button>
            </template>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button @click="$dispatch('open-modal', 'add-row')" class="btn-outline !py-1.5 !px-3">
                <x-icon name="plus" class="w-4 h-4"/> Add row
            </button>
            <a :href="exportUrl + '?format=csv&' + queryString()" class="btn-outline !py-1.5 !px-3">
                <x-icon name="download" class="w-4 h-4"/> CSV
            </a>
            <a :href="exportUrl + '?format=json&' + queryString()" class="btn-outline !py-1.5 !px-3">
                <x-icon name="download" class="w-4 h-4"/> JSON
            </a>
            <select x-model.number="perPage" @change="resetAndLoad()" class="input !w-auto !py-1.5">
                @foreach ($perPageOptions as $opt)
                    <option value="{{ $opt }}">{{ $opt }} / page</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Table --}}
    <div class="relative max-h-[70vh] overflow-auto">
        <table class="w-full text-left text-sm">
            <thead class="sticky top-0 z-10 bg-slate-50/95 backdrop-blur dark:bg-ink-850/95">
                <tr class="text-xs uppercase tracking-wide text-slate-400">
                    <th class="w-10 px-4 py-3">
                        <input type="checkbox" @change="toggleAll($event)"
                               class="rounded border-slate-300 text-brand-600 dark:bg-ink-850 dark:border-white/10">
                    </th>
                    @foreach ([
                        ['external_id','ID'], ['name','Name'], ['username','Username'],
                        ['platform','Platform'], ['followers','Followers'], ['following','Following'],
                        ['posts','Posts'], ['is_valid','Valid'], ['is_qualified','Qualified'],
                    ] as [$col, $label])
                        <th class="cursor-pointer whitespace-nowrap px-4 py-3 hover:text-slate-600 dark:hover:text-slate-200"
                            @click="sortBy('{{ $col }}')">
                            <span class="inline-flex items-center gap-1">
                                {{ $label }}
                                <template x-if="filters.sort === '{{ $col }}'">
                                    <x-icon name="chevron-down" class="w-3 h-3 transition"
                                            ::class="filters.dir === 'asc' && 'rotate-180'"/>
                                </template>
                            </span>
                        </th>
                    @endforeach
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                {{-- Skeleton --}}
                <template x-if="loading">
                    <template x-for="i in perPage > 12 ? 12 : perPage" :key="i">
                        <tr>
                            <td class="px-4 py-3" colspan="11"><div class="skeleton h-5 w-full"></div></td>
                        </tr>
                    </template>
                </template>

                <template x-if="!loading && rows.length === 0">
                    <tr><td colspan="11" class="px-4 py-16 text-center text-slate-400">No rows match your filters.</td></tr>
                </template>

                <template x-for="row in rows" :key="row.id">
                    <tr class="transition hover:bg-slate-50/70 dark:hover:bg-white/[0.03]"
                        :class="edit.id === row.id && 'bg-brand-50/40 dark:bg-brand-500/5'">
                        <td class="px-4 py-3">
                            <input type="checkbox" :value="row.id" x-model.number="selected"
                                   class="rounded border-slate-300 text-brand-600 dark:bg-ink-850 dark:border-white/10">
                        </td>

                        {{-- Read mode --}}
                        <template x-if="edit.id !== row.id">
                            <td class="px-4 py-3 text-slate-400" x-text="row.external_id || '—'"></td>
                        </template>
                        <template x-if="edit.id !== row.id">
                            <td class="px-4 py-3 font-medium text-slate-700 dark:text-slate-200" x-text="row.name || '—'"></td>
                        </template>
                        <template x-if="edit.id !== row.id">
                            <td class="px-4 py-3 text-slate-500 dark:text-slate-400">
                                <a :href="row.profile_url || '#'" target="_blank" rel="noopener"
                                   class="hover:text-brand-500" x-text="row.username ? '@'+row.username : '—'"></a>
                            </td>
                        </template>
                        <template x-if="edit.id !== row.id">
                            <td class="px-4 py-3"><span class="badge-slate" x-text="row.platform || '—'"></span></td>
                        </template>
                        <template x-if="edit.id !== row.id">
                            <td class="px-4 py-3 font-semibold text-slate-700 dark:text-slate-200" x-text="fmt(row.followers)"></td>
                        </template>
                        <template x-if="edit.id !== row.id">
                            <td class="px-4 py-3 text-slate-500" x-text="fmt(row.following)"></td>
                        </template>
                        <template x-if="edit.id !== row.id">
                            <td class="px-4 py-3 text-slate-500" x-text="fmt(row.posts)"></td>
                        </template>
                        <template x-if="edit.id !== row.id">
                            <td class="px-4 py-3">
                                <span :class="row.is_valid ? 'badge-green' : 'badge-red'"
                                      x-text="row.is_valid ? 'Valid' : 'Invalid'"></span>
                            </td>
                        </template>
                        <template x-if="edit.id !== row.id">
                            <td class="px-4 py-3">
                                <span :class="row.is_qualified ? 'badge-amber' : 'badge-slate'"
                                      x-text="row.is_qualified ? 'Yes' : 'No'"></span>
                            </td>
                        </template>

                        {{-- Edit mode --}}
                        <template x-if="edit.id === row.id">
                            <td class="px-2 py-2"><input x-model="edit.external_id" class="input !py-1.5 !text-xs"></td>
                        </template>
                        <template x-if="edit.id === row.id">
                            <td class="px-2 py-2"><input x-model="edit.name" class="input !py-1.5 !text-xs"></td>
                        </template>
                        <template x-if="edit.id === row.id">
                            <td class="px-2 py-2"><input x-model="edit.username" class="input !py-1.5 !text-xs"></td>
                        </template>
                        <template x-if="edit.id === row.id">
                            <td class="px-2 py-2"><input x-model="edit.platform" class="input !py-1.5 !text-xs"></td>
                        </template>
                        <template x-if="edit.id === row.id">
                            <td class="px-2 py-2"><input type="number" x-model.number="edit.followers" class="input !py-1.5 !text-xs"></td>
                        </template>
                        <template x-if="edit.id === row.id">
                            <td class="px-2 py-2"><input type="number" x-model.number="edit.following" class="input !py-1.5 !text-xs"></td>
                        </template>
                        <template x-if="edit.id === row.id">
                            <td class="px-2 py-2"><input type="number" x-model.number="edit.posts" class="input !py-1.5 !text-xs"></td>
                        </template>
                        <template x-if="edit.id === row.id">
                            <td class="px-2 py-2">
                                <select x-model.number="edit.is_valid" class="input !py-1.5 !text-xs">
                                    <option value="1">Valid</option><option value="0">Invalid</option>
                                </select>
                            </td>
                        </template>
                        <template x-if="edit.id === row.id">
                            <td class="px-2 py-2">
                                <select x-model.number="edit.is_qualified" class="input !py-1.5 !text-xs">
                                    <option value="1">Yes</option><option value="0">No</option>
                                </select>
                            </td>
                        </template>

                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <template x-if="edit.id !== row.id">
                                    <button @click="startEdit(row)" class="btn-ghost !px-2 !py-1" title="Edit">
                                        <x-icon name="edit" class="w-4 h-4"/>
                                    </button>
                                </template>
                                <template x-if="edit.id !== row.id">
                                    <button @click="destroy(row)" class="btn-ghost !px-2 !py-1 text-rose-500" title="Delete">
                                        <x-icon name="trash" class="w-4 h-4"/>
                                    </button>
                                </template>
                                <template x-if="edit.id === row.id">
                                    <button @click="saveEdit()" class="btn-ghost !px-2 !py-1 text-emerald-500" title="Save">
                                        <x-icon name="check" class="w-4 h-4"/>
                                    </button>
                                </template>
                                <template x-if="edit.id === row.id">
                                    <button @click="edit.id = null" class="btn-ghost !px-2 !py-1" title="Cancel">
                                        <x-icon name="x" class="w-4 h-4"/>
                                    </button>
                                </template>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    {{-- Pagination --}}
    <div class="flex flex-col items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 text-sm dark:border-white/5 sm:flex-row">
        <p class="text-slate-500 dark:text-slate-400">
            Showing <span class="font-semibold" x-text="meta.from ?? 0"></span>–<span class="font-semibold" x-text="meta.to ?? 0"></span>
            of <span class="font-semibold" x-text="meta.total ?? 0"></span>
        </p>
        <div class="flex items-center gap-1">
            <button @click="go(meta.current_page - 1)" :disabled="meta.current_page <= 1"
                    class="btn-ghost !px-2 !py-1.5 disabled:opacity-40">
                <x-icon name="chevron-left" class="w-4 h-4"/>
            </button>
            <span class="px-3 text-slate-500 dark:text-slate-400">
                <span x-text="meta.current_page ?? 1"></span> / <span x-text="meta.last_page ?? 1"></span>
            </span>
            <button @click="go(meta.current_page + 1)" :disabled="meta.current_page >= meta.last_page"
                    class="btn-ghost !px-2 !py-1.5 disabled:opacity-40">
                <x-icon name="chevron-right" class="w-4 h-4"/>
            </button>
        </div>
    </div>

    {{-- Add row modal --}}
    <x-modal name="add-row" title="Add a new row" max-width="max-w-xl">
        <form @submit.prevent="storeRow()" class="grid grid-cols-2 gap-4">
            <div class="col-span-2"><label class="label">Name</label><input x-model="form.name" class="input"></div>
            <div><label class="label">Username</label><input x-model="form.username" class="input"></div>
            <div><label class="label">External ID</label><input x-model="form.external_id" class="input"></div>
            <div><label class="label">Platform</label><input x-model="form.platform" class="input" placeholder="instagram"></div>
            <div><label class="label">Followers</label><input type="number" x-model.number="form.followers" class="input"></div>
            <div><label class="label">Following</label><input type="number" x-model.number="form.following" class="input"></div>
            <div><label class="label">Posts</label><input type="number" x-model.number="form.posts" class="input"></div>
            <div><label class="label">Valid</label>
                <select x-model.number="form.is_valid" class="input"><option value="1">Valid</option><option value="0">Invalid</option></select>
            </div>
            <div><label class="label">Qualified</label>
                <select x-model.number="form.is_qualified" class="input"><option value="1">Yes</option><option value="0">No</option></select>
            </div>
            <div class="col-span-2"><label class="label">Profile URL</label><input x-model="form.profile_url" class="input"></div>
            <div class="col-span-2 mt-2 flex justify-end gap-2">
                <button type="button" @click="$dispatch('close-modal','add-row')" class="btn-outline">Cancel</button>
                <button class="btn-primary"><x-icon name="check" class="w-4 h-4"/> Save row</button>
            </div>
        </form>
    </x-modal>
</div>

@push('scripts')
<script>
function datasetTable(cfg) {
    return {
        ...cfg,
        rows: [], meta: {}, loading: true, selected: [],
        filters: { search: '', platform: '', valid: '', qualified: '', bucket: '', sort: 'followers', dir: 'desc' },
        edit: { id: null },
        form: { name: '', username: '', external_id: '', platform: '', followers: 0, following: 0, posts: 0, is_valid: 1, is_qualified: 0, profile_url: '' },
        page: 1,

        csrf() { return document.querySelector('meta[name=csrf-token]').content; },
        fmt(n) { return new Intl.NumberFormat().format(n ?? 0); },

        queryString() {
            const p = new URLSearchParams({ ...this.filters, per_page: this.perPage, page: this.page });
            return p.toString();
        },

        async load() {
            this.loading = true;
            try {
                const res = await fetch(this.url + '?' + this.queryString(), { headers: { 'Accept': 'application/json' } });
                const json = await res.json();
                this.rows = json.data;
                this.meta = json.meta;
            } catch (e) { window.toast('Failed to load rows', 'error'); }
            this.loading = false;
            this.selected = [];
        },
        resetAndLoad() { this.page = 1; this.load(); },
        go(p) { if (p >= 1 && p <= this.meta.last_page) { this.page = p; this.load(); } },
        sortBy(col) {
            if (this.filters.sort === col) this.filters.dir = this.filters.dir === 'asc' ? 'desc' : 'asc';
            else { this.filters.sort = col; this.filters.dir = 'desc'; }
            this.resetAndLoad();
        },
        resetFilters() {
            this.filters = { search: '', platform: '', valid: '', qualified: '', bucket: '', sort: 'followers', dir: 'desc' };
            this.resetAndLoad();
        },
        toggleAll(e) { this.selected = e.target.checked ? this.rows.map(r => r.id) : []; },

        startEdit(row) { this.edit = { ...row, is_valid: row.is_valid ? 1 : 0, is_qualified: row.is_qualified ? 1 : 0 }; },
        async saveEdit() {
            const res = await fetch(this.rowUrl + '/' + this.edit.id, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' },
                body: JSON.stringify(this.edit),
            });
            if (res.ok) { window.toast('Row updated'); this.edit = { id: null }; this.load(); }
            else window.toast('Update failed', 'error');
        },
        async destroy(row) {
            if (!confirm('Delete this row?')) return;
            const res = await fetch(this.rowUrl + '/' + row.id, {
                method: 'DELETE', headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' },
            });
            if (res.ok) { window.toast('Row deleted'); this.load(); }
            else window.toast('Delete failed', 'error');
        },
        async bulkDelete() {
            if (!confirm(`Delete ${this.selected.length} selected rows?`)) return;
            const res = await fetch(this.bulkUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' },
                body: JSON.stringify({ ids: this.selected }),
            });
            if (res.ok) { const j = await res.json(); window.toast(`${j.deleted} rows deleted`); this.load(); }
            else window.toast('Bulk delete failed', 'error');
        },
        async storeRow() {
            const res = await fetch(this.storeUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' },
                body: JSON.stringify(this.form),
            });
            if (res.ok) {
                window.toast('Row added');
                this.$dispatch('close-modal', 'add-row');
                this.form = { name: '', username: '', external_id: '', platform: '', followers: 0, following: 0, posts: 0, is_valid: 1, is_qualified: 0, profile_url: '' };
                this.resetAndLoad();
            } else window.toast('Could not add row (check fields)', 'error');
        },
    };
}
</script>
@endpush
