<?php

namespace App\Http\Controllers;

use App\Enums\ContactStatus;
use App\Enums\Permission;
use App\Enums\RegionLevel;
use App\Models\Contact;
use App\Models\Region;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The contact (UID) database, and the agent register built on top of it.
 *
 * These records hold real names, phone numbers and addresses, so every action
 * here is permission-gated and written to the audit trail — including reads
 * that export data.
 */
class ContactController extends Controller
{
    public function __construct(private readonly ActivityLogger $log) {}

    public function index(Request $request): View
    {
        $this->authorize(Permission::ViewContacts->value);

        $contacts = Contact::query()
            ->canonical()
            ->search($request->input('q'))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('region'), fn ($q, $v) => $q->whereIn(
                'region_id',
                $this->regionSubtree((int) $v),
            ))
            ->when($request->input('has_phone') === '1', fn ($q) => $q->whereNotNull('phone_e164'))
            ->with(['identities', 'region', 'owner:id,name'])
            ->withCount('interactions')
            // Terbaru dulu, seperti semua daftar di aplikasi.
            ->latest()
            // Pembeda terakhir. Tanpa kunci unik di akhir urutan, dua baris
            // dengan waktu yang sama boleh muncul dalam urutan berbeda tiap
            // query — dan saat dipaginasi, satu baris bisa terlewat sama
            // sekali tanpa gejala apa pun.
            ->orderByDesc('id')
            ->paginate(24)
            ->withQueryString();

        return view('contacts.index', [
            'contacts' => $contacts,
            'statuses' => ContactStatus::options(),
            'provinces' => Region::level(RegionLevel::Province)->orderBy('name')->get(),
            'stats' => $this->stats(),
            'filters' => $request->only('q', 'status', 'region', 'has_phone'),
        ]);
    }

    /** The agent register — same data, filtered, with its own columns. */
    public function agents(Request $request): View
    {
        $this->authorize(Permission::ViewContacts->value);

        $agents = Contact::query()
            ->canonical()
            ->agents()
            ->search($request->input('q'))
            ->with(['identities', 'region', 'recruiter:id,name'])
            ->withCount('interactions')
            ->orderByDesc('agent_since')
            ->orderBy('id')
            ->paginate(24)
            ->withQueryString();

        return view('contacts.agents', [
            'agents' => $agents,
            'filters' => $request->only('q'),
        ]);
    }

    public function show(Contact $contact): View
    {
        $this->authorize(Permission::ViewContacts->value);

        $contact->load(['identities', 'region', 'owner', 'recruiter', 'followUps.user']);

        return view('contacts.show', [
            'contact' => $contact,
            'interactions' => $contact->interactions()->with('source')->paginate(15),
            'provinces' => Region::level(RegionLevel::Province)->orderBy('name')->get(),
            'owners' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'statuses' => ContactStatus::options(),
        ]);
    }

    /**
     * The operator's enrichment form: real name, region, phone, notes.
     *
     * One form, three buttons, carried by `intent`:
     *
     *   save      — just store what was filled in (the default)
     *   candidate — store, then flag as a prospective agent
     *   promote   — store, then make them an agent
     *
     * Saving never requires anything: an operator who only learned a phone
     * number, or only wants to leave a note, saves that and moves on. Only
     * `promote` adds a requirement, because an agent is someone we publish and
     * route work to, and it reports per field so the message appears under the
     * input it concerns.
     *
     * `status` itself is never accepted from the form — the agent code and
     * timestamp are set together in promoteToAgent() so they cannot disagree.
     */
    public function update(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorize(Permission::ManageContacts->value);

        $data = $request->validate([
            'full_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'address_detail' => ['nullable', 'string', 'max:512'],
            'potential_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $phone = PhoneNumber::normalize($data['phone'] ?? null);

        if (filled($data['phone'] ?? null) && $phone === null) {
            return back()
                ->withInput()
                ->withErrors(['phone' => 'Nomor tidak dikenali sebagai nomor Indonesia yang valid.']);
        }

        // The phone column is unique; catch the clash here so the operator gets
        // a useful message instead of a database error page.
        if ($phone && Contact::where('phone_e164', $phone)->whereKeyNot($contact->getKey())->exists()) {
            return back()
                ->withInput()
                ->withErrors(['phone' => 'Nomor ini sudah terdaftar pada kontak lain.']);
        }

        /*
         * Only the fields the form actually submitted are written.
         *
         * The previous version assigned every column from `$data ?? null`,
         * which meant any form that did not carry all eight — a compact panel,
         * a future inline editor — silently erased the rest. A field that is
         * present but empty is still a deliberate clear; a field that is absent
         * is simply not this form's business.
         */
        $changes = [];

        foreach (['full_name', 'email', 'region_id', 'address_detail', 'potential_score', 'owner_id', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }

        if (array_key_exists('phone', $data)) {
            $changes['phone_e164'] = $phone;
        }

        $contact->update($changes);

        $this->log->log('contact.updated', "Melengkapi data kontak {$contact->code}", $contact);

        return match ($request->input('intent')) {
            'promote' => $this->promoteAfterSave($request, $contact),
            'candidate' => $this->candidateAfterSave($contact),
            default => back()->with('success', 'Data kontak diperbarui.'),
        };
    }

    /**
     * "Simpan & Jadikan Agent" — the data is already stored by the time we get
     * here, so a refusal never costs the operator their typing.
     */
    private function promoteAfterSave(Request $request, Contact $contact): RedirectResponse
    {
        if (! $request->user()->hasPermission(Permission::ManageAgents)) {
            return back()->with('success', 'Data kontak diperbarui.')
                ->withErrors(['intent' => 'Anda tidak berhak menjadikan agent.']);
        }

        if ($missing = $this->missingForAgent($contact)) {
            return back()->withInput()->withErrors($missing);
        }

        $contact->promoteToAgent($request->user());

        $this->log->log(
            'contact.promoted',
            "Menjadikan {$contact->name()} sebagai agent ({$contact->agent_code})",
            $contact,
        );

        return back()->with('success', "Data tersimpan. {$contact->name()} kini agent {$contact->agent_code}.");
    }

    private function candidateAfterSave(Contact $contact): RedirectResponse
    {
        if ($contact->status === ContactStatus::NonAgent) {
            $contact->forceFill(['status' => ContactStatus::Candidate])->save();

            $this->log->log('contact.candidate', "Menandai {$contact->name()} sebagai calon agent", $contact);
        }

        return back()->with('success', 'Data tersimpan dan ditandai sebagai calon agent.');
    }

    /**
     * What still stops this person becoming an agent, keyed by field.
     *
     * @return array<string, string>
     */
    private function missingForAgent(Contact $contact): array
    {
        return array_filter([
            'full_name' => blank($contact->full_name) ? 'Isi nama asli sebelum menjadikan agent.' : null,
            'phone' => blank($contact->phone_e164) ? 'Isi nomor WhatsApp sebelum menjadikan agent.' : null,
        ]);
    }

    /** "Jadikan Agent" — the button from the brief. */
    /**
     * "Jadikan Agent" — and, when the record is not complete yet, the place
     * that completes it.
     *
     * An agent is someone we will publish and route work to, so the name and
     * number are genuinely required. But refusing from a screen that offers no
     * way to supply them is a dead end: the operator is told what is missing
     * and given nowhere to type it. So this accepts both fields optionally and
     * fills them in the same submit, which is what the compact panels on the
     * interaction and contact pages post.
     */
    public function promote(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorize(Permission::ManageAgents->value);

        $data = $request->validate([
            'full_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
        ], [], ['full_name' => 'nama asli', 'phone' => 'nomor WhatsApp']);

        if (filled($data['full_name'] ?? null)) {
            $contact->full_name = $data['full_name'];
        }

        if (filled($data['phone'] ?? null)) {
            $phone = PhoneNumber::normalize($data['phone']);

            if ($phone === null) {
                return back()->withInput()->withErrors([
                    'phone' => 'Nomor tidak dikenali sebagai nomor Indonesia yang valid.',
                ]);
            }

            if (Contact::where('phone_e164', $phone)->whereKeyNot($contact->getKey())->exists()) {
                return back()->withInput()->withErrors([
                    'phone' => 'Nomor ini sudah terdaftar pada kontak lain.',
                ]);
            }

            $contact->phone_e164 = $phone;
        }

        // Errors are keyed to the individual fields, so each message lands under
        // the input it is about rather than in one lump the user has to decode.
        if ($missing = $this->missingForAgent($contact)) {
            return back()->withInput()->withErrors($missing);
        }

        if ($contact->isDirty()) {
            $contact->save();
        }

        $contact->promoteToAgent($request->user());

        $this->log->log(
            'contact.promoted',
            "Menjadikan {$contact->name()} sebagai agent ({$contact->agent_code})",
            $contact,
        );

        return back()->with('success', "{$contact->name()} kini terdaftar sebagai agent {$contact->agent_code}.");
    }

    public function demote(Contact $contact): RedirectResponse
    {
        $this->authorize(Permission::ManageAgents->value);

        $contact->demoteFromAgent();

        $this->log->log('contact.demoted', "Mencabut status agent {$contact->name()}", $contact);

        return back()->with('success', 'Status agent dicabut.');
    }

    /** Mark someone as a candidate without committing to full agent status. */
    public function markCandidate(Contact $contact): RedirectResponse
    {
        $this->authorize(Permission::ManageContacts->value);

        $contact->forceFill(['status' => ContactStatus::Candidate])->save();

        $this->log->log('contact.candidate', "Menandai {$contact->name()} sebagai calon agent", $contact);

        return back()->with('success', 'Ditandai sebagai calon agent.');
    }

    /** Cascading region dropdowns — returns the children of one region. */
    public function regions(Request $request): JsonResponse
    {
        $this->authorize(Permission::ViewContacts->value);

        $parent = $request->input('parent');

        $children = Region::query()
            // Tanpa `parent` berarti tingkat teratas — dan provinsi ber-parent_id
            // NULL, bukan 0. `$request->integer()` memulangkan 0 untuk nilai
            // kosong, sehingga query mencari parent_id = 0 dan tidak pernah
            // menemukan satu provinsi pun.
            ->when(blank($parent), fn ($q) => $q->whereNull('parent_id'))
            ->when(filled($parent), fn ($q) => $q->where('parent_id', (int) $parent))
            ->orderBy('name')
            ->get(['id', 'name', 'level']);

        return response()->json($children);
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    /**
     * Every region id at or below the given one, so filtering by a province
     * also returns contacts recorded at village level.
     *
     * Four queries deep rather than a recursive CTE, because the tree is
     * exactly four levels and this keeps working on older MySQL.
     *
     * @return array<int, int>
     */
    private function regionSubtree(int $rootId): array
    {
        $ids = [$rootId];
        $frontier = [$rootId];

        for ($depth = 0; $depth < 3 && $frontier !== []; $depth++) {
            $frontier = Region::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    /** @return array<string, int> */
    private function stats(): array
    {
        $byStatus = Contact::canonical()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'total' => (int) $byStatus->sum(),
            'agents' => (int) ($byStatus[ContactStatus::Agent->value] ?? 0),
            'candidates' => (int) ($byStatus[ContactStatus::Candidate->value] ?? 0),
            'with_phone' => Contact::canonical()->whereNotNull('phone_e164')->count(),
        ];
    }
}
