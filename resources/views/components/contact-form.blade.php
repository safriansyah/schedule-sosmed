@props([
    'contact',
    'owners' => [],
    'canManage' => false,
    'canAgent' => false,
])

{{--
    The one contact form.

    Shared by the contact page and the interaction sidebar so the two can never
    drift: whatever "complete this person's record" means, it means the same
    fields in both places.

    Everything is optional. An operator who only learned one thing — a note, a
    phone number — saves just that and moves on. The single exception is
    pressing "Simpan & Jadikan Agent", which needs a real name and a number
    because an agent is someone we will publish and route work to; that check
    lives in ContactController::update() and reports per field, so the message
    lands under the input it is about.
--}}

<form method="POST" action="{{ route('contacts.update', $contact) }}" class="space-y-4">
    @csrf
    @method('PUT')

    <fieldset @disabled(! $canManage) class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="full_name" class="label">Nama asli</label>
                <input id="full_name" name="full_name" class="input"
                       value="{{ old('full_name', $contact->full_name) }}"
                       placeholder="Nama sesuai identitas">
                @error('full_name') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="phone" class="label">Nomor WhatsApp</label>
                <input id="phone" name="phone" class="input"
                       value="{{ old('phone', \App\Support\PhoneNumber::pretty($contact->phone_e164)) }}"
                       placeholder="0812xxxxxxx">
                {{-- Any of 0812…, +62812… or 62812… is accepted; it is
                     normalised on save so duplicates cannot form. --}}
                <p class="mt-1 text-[11px] text-slate-400">Boleh ditulis 0812…, +62812… atau 62812…</p>
                @error('phone') <p class="form-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="email" class="label">Email</label>
                <input id="email" name="email" type="email" class="input"
                       value="{{ old('email', $contact->email) }}">
                @error('email') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="owner_id" class="label">Petugas penanggung jawab</label>
                <select id="owner_id" name="owner_id" class="input">
                    <option value="">Belum ditentukan</option>
                    @foreach ($owners as $owner)
                        <option value="{{ $owner->id }}" @selected((int) old('owner_id', $contact->owner_id) === $owner->id)>
                            {{ $owner->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Cascading region pickers. Each level loads the next from
             /contacts/regions, so the browser never holds the whole 80k-row
             wilayah table. --}}
        <div x-data="regionPicker({
                initial: @js($contact->region_id),
                path: @js(collect([...$contact->region?->ancestors() ?? [], $contact->region])->filter()->map->only(['id', 'level'])->values()),
                url: @js(route('contacts.regions')),
             })" x-init="boot()">
            <label class="label">Wilayah</label>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <template x-for="level in levels" :key="level.key">
                    <select class="input"
                            :disabled="!level.options.length"
                            x-model="level.selected"
                            @change="pick(level)">
                        <option value="" x-text="level.placeholder"></option>
                        <template x-for="option in level.options" :key="option.id">
                            <option :value="option.id" x-text="option.name"></option>
                        </template>
                    </select>
                </template>
            </div>

            {{-- The deepest level actually chosen is what gets saved. --}}
            <input type="hidden" name="region_id" :value="deepest()">
            @error('region_id') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="address_detail" class="label">Alamat detail</label>
            <input id="address_detail" name="address_detail" class="input"
                   value="{{ old('address_detail', $contact->address_detail) }}"
                   placeholder="Nama jalan, RT/RW, patokan…">
        </div>

        {{-- x-data wraps label AND input so the live readout shares the
             slider's scope. --}}
        <div x-data="{ score: {{ (int) old('potential_score', $contact->potential_score) }} }">
            <label for="potential_score" class="label">
                Potensi jadi mahasiswa — <span x-text="score"></span>%
            </label>
            <input id="potential_score" name="potential_score" type="range"
                   min="0" max="100" step="5" x-model="score"
                   class="mt-2 w-full accent-violet-500">
        </div>

        <div>
            <label for="notes" class="label">Catatan</label>
            <textarea id="notes" name="notes" rows="3" class="input"
                      placeholder="Konteks yang perlu diketahui petugas lain…">{{ old('notes', $contact->notes) }}</textarea>
        </div>
    </fieldset>

    @if ($canManage)
        <div class="flex flex-wrap gap-2">
            {{-- `intent` tells the controller which button was pressed, so one
                 form serves all three actions without duplicating the fields
                 or resorting to formaction (which cannot carry the PUT spoof). --}}
            <button name="intent" value="save" class="btn-primary">
                <x-icon name="check" class="h-4 w-4"/> Simpan
            </button>

            @if ($canAgent && ! $contact->isAgent())
                <button name="intent" value="promote" class="btn-success">
                    <x-icon name="badge-check" class="h-4 w-4"/> Simpan &amp; Jadikan Agent
                </button>
            @endif

            @if ($contact->status === \App\Enums\ContactStatus::NonAgent)
                <button name="intent" value="candidate" class="btn-outline">
                    <x-icon name="user-plus" class="h-4 w-4"/> Tandai Calon Agent
                </button>
            @endif
        </div>

        <p class="text-[11px] text-slate-400">
            Semua kolom boleh dikosongkan — isi seadanya lalu Simpan.
            Nama asli dan nomor WhatsApp baru wajib saat menjadikan agent.
        </p>
    @endif
</form>

@once
    @push('scripts')
        <script>
            /**
             * Four dependent selects: provinsi → kabupaten → kecamatan → desa.
             *
             * Children are fetched on demand instead of shipping the whole
             * wilayah table to the browser, and `path` re-hydrates the chain on
             * load so an already-saved region shows as selected.
             */
            document.addEventListener('alpine:init', () => {
                Alpine.data('regionPicker', ({ initial, path, url }) => ({
                    levels: [
                        { key: 'provinsi',  placeholder: 'Pilih provinsi',  selected: '', options: [] },
                        { key: 'kabupaten', placeholder: 'Kabupaten / Kota', selected: '', options: [] },
                        { key: 'kecamatan', placeholder: 'Kecamatan',       selected: '', options: [] },
                        { key: 'desa',      placeholder: 'Desa / Kelurahan', selected: '', options: [] },
                    ],

                    async boot() {
                        await this.load(0, null);

                        // Walk the saved chain so each level shows its selection.
                        for (let i = 0; i < path.length; i++) {
                            this.levels[i].selected = String(path[i].id);

                            if (i + 1 < this.levels.length) {
                                await this.load(i + 1, path[i].id);
                            }
                        }
                    },

                    async load(index, parentId) {
                        const level = this.levels[index];

                        if (!level) return;

                        const response = await fetch(`${url}?parent=${parentId ?? ''}`, {
                            headers: { Accept: 'application/json' },
                        });

                        level.options = response.ok ? await response.json() : [];
                    },

                    async pick(level) {
                        const index = this.levels.indexOf(level);

                        // Anything below the level just changed is now stale.
                        for (let i = index + 1; i < this.levels.length; i++) {
                            this.levels[i].selected = '';
                            this.levels[i].options = [];
                        }

                        if (level.selected) {
                            await this.load(index + 1, level.selected);
                        }
                    },

                    /** The deepest level with a selection — that is the region we store. */
                    deepest() {
                        for (let i = this.levels.length - 1; i >= 0; i--) {
                            if (this.levels[i].selected) return this.levels[i].selected;
                        }

                        return '';
                    },
                }));
            });
        </script>
    @endpush
@endonce
