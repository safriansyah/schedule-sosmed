<?php

namespace App\Http\Controllers;

use App\Enums\FollowUpAction;
use App\Enums\FollowUpOutcome;
use App\Enums\Permission;
use App\Enums\FollowUpStatus;
use App\Enums\Priority;
use App\Enums\RoleName;
use App\Enums\TicketFlag;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Models\Interaction;
use App\Models\Student;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketDetail;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Students\StudentStats;
use App\Services\Tickets\TicketNumberFormatter;
use App\Services\Export\Exporter;
use App\Services\Tickets\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Ticketing — the case list, one case, and everything done to it.
 *
 * Follow-ups live inside the ticket detail (there is deliberately no
 * standalone Follow Up menu), and closing a ticket keeps it: it stays in the
 * list under the Closed filter with its full history.
 */
class TicketController extends Controller
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly ActivityLogger $log,
        private readonly StudentStats $stats,
        private readonly TicketNumberFormatter $numbers,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize(Permission::ViewTickets->value);

        $user = $request->user();
        $filters = $this->filters($request);

        // "My Tickets" is the same screen with one filter pre-set, rather than
        // a second list that could drift out of step with this one.
        $mine = $request->routeIs('tickets.mine');

        // Region only means something for tickets that came from the student
        // import — an Instagram commenter has no address.
        $showRegion = ($filters['source'] ?? null) === TicketSource::StudentImport->value;

        $tickets = Ticket::query()
            ->visibleTo($user)
            ->when($mine, fn ($q) => $q->where('assigned_to', $user->id))
            ->filtered($filters)
            ->with(['assignee:id,name', 'category:id,name', 'subCategory:id,name'])
            ->orderByRaw("FIELD(status, 'open', 'assigned', 'in_progress', 'follow_up', 'resolved', 'closed')")
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('tickets.index', [
            'tickets' => $tickets,
            'filters' => $filters,
            'mine' => $mine,
            'stats' => $this->stats($user),
            'statuses' => TicketStatus::options(),
            'priorities' => Priority::options(),
            'sources' => TicketSource::options(),
            'flags' => TicketFlag::options(),
            'categories' => TicketCategory::roots()->active()->ordered()->get(),
            'operators' => $this->operators(),
            // The region selects appear only for Import Mahasiswa, so their
            // options are only looked up then — four DISTINCT scans are not
            // worth running for a list that will not be rendered.
            'showRegion' => $showRegion,
            'regions' => $showRegion ? $this->stats->regionTree($user, $filters) : [],
            'regionLevels' => StudentStats::REGION_LEVELS,
            'regionLabels' => StudentStats::regionLabels(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize(Permission::CreateTickets->value);

        return view('tickets.create', [
            'categories' => TicketCategory::roots()->active()->ordered()->with('children')->get(),
            'statuses' => TicketStatus::options(),
            'priorities' => Priority::options(),
            'sources' => TicketSource::options(),
            'flags' => TicketFlag::options(),
            'operators' => $this->operators(),
            'student' => $request->filled('student')
                ? Student::find($request->integer('student'))
                : null,
            // The admin's list of numbering formats (TKU, TKB, …). A manual
            // ticket may pick one; everything raised automatically uses the
            // default.
            'numberFormats' => $this->numbers->options(),
            'defaultFormat' => $this->numbers->defaultFormat()['code'],
            'nextNumber' => Ticket::nextNumber(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permission::CreateTickets->value);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'source' => ['required', 'string', 'max:32'],
            'category_id' => ['nullable', 'integer', 'exists:ticket_categories,id'],
            'sub_category_id' => ['nullable', 'integer', 'exists:ticket_categories,id'],
            'priority' => ['required', 'string', 'max:16'],
            'flag' => ['nullable', 'string', 'max:16'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'requester_name' => ['nullable', 'string', 'max:255'],
            'requester_nim' => ['nullable', 'string', 'max:32'],
            'requester_nac' => ['nullable', 'string', 'max:64'],
            'requester_phone' => ['nullable', 'string', 'max:32'],
            'requester_email' => ['nullable', 'email', 'max:255'],
            'due_at' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'attachment' => ['nullable', 'file', 'max:10240'],
            'number_format' => ['nullable', 'string', 'max:8'],
        ], [], [
            'subject' => 'judul',
            'source' => 'sumber',
            'priority' => 'prioritas',
        ]);

        // Only an assigner may hand the new ticket straight to someone.
        if (! $request->user()->hasPermission(Permission::AssignTickets)) {
            unset($data['assigned_to']);
        }

        // Stored on the `public` disk so the link works without a signed route;
        // nothing secret is expected here, and follow-up evidence works the
        // same way.
        if ($file = $request->file('attachment')) {
            $data['attachment_path'] = $file->store('tickets', 'public');
            $data['attachment_name'] = $file->getClientOriginalName();
        }

        unset($data['attachment']);

        $ticket = $this->tickets->createManual($data, $request->user());

        return redirect()
            ->route('tickets.show', $ticket)
            ->with('success', "Tiket {$ticket->number} dibuat.");
    }

    /**
     * "Add to Ticket" from the Instagram comment list.
     *
     * Idempotent in the service, so the operator is redirected to the existing
     * ticket rather than shown an error if the comment already has one.
     */
    public function fromInteraction(Request $request, Interaction $interaction): RedirectResponse
    {
        $this->authorize(Permission::CreateTickets->value);

        $existing = Ticket::where('interaction_id', $interaction->getKey())->first();

        $ticket = $this->tickets->createFromInteraction($interaction, $request->user());

        return redirect()
            ->route('tickets.show', $ticket)
            ->with(
                $existing ? 'info' : 'success',
                $existing
                    ? "Komentar ini sudah punya tiket {$ticket->number}."
                    : "Tiket {$ticket->number} dibuat dari komentar @{$interaction->author_handle}.",
            );
    }

    public function show(Request $request, Ticket $ticket): View
    {
        $this->authorize(Permission::ViewTickets->value);
        $this->guardVisibility($request->user(), $ticket);

        $ticket->load([
            'category', 'subCategory', 'assignee:id,name', 'creator:id,name', 'closer:id,name',
            'student', 'contact', 'interaction',
            // The whole row, not three columns: the "Detail Mahasiswa"
            // block renders every field the import carries, and a
            // narrowed select would silently blank most of them.
            'details.student',
            'followUps.user:id,name',
            'assignments.fromUser:id,name', 'assignments.toUser:id,name', 'assignments.assigner:id,name',
        ]);

        return view('tickets.show', [
            'ticket' => $ticket,
            'activities' => $ticket->activities()->with('user:id,name')->limit(50)->get(),
            'statuses' => TicketStatus::options(),
            'priorities' => Priority::options(),
            'flags' => TicketFlag::options(),
            // The follow-up form offers the four-state vocabulary, not the six.
            'followUpStatuses' => FollowUpStatus::selectable(),
            'actions' => FollowUpAction::options(),
            'outcomes' => FollowUpOutcome::options(),
            'operators' => $this->operators(),
            'categories' => TicketCategory::roots()->active()->ordered()->with('children')->get(),
        ]);
    }

    public function update(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize(Permission::HandleTickets->value);
        $this->guardVisibility($request->user(), $ticket);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'category_id' => ['nullable', 'integer', 'exists:ticket_categories,id'],
            'sub_category_id' => ['nullable', 'integer', 'exists:ticket_categories,id'],
            'priority' => ['required', 'string', 'max:16'],
            'requester_name' => ['nullable', 'string', 'max:255'],
            'requester_nim' => ['nullable', 'string', 'max:32'],
            'requester_nac' => ['nullable', 'string', 'max:64'],
            'requester_phone' => ['nullable', 'string', 'max:32'],
            'requester_email' => ['nullable', 'email', 'max:255'],
            'due_at' => ['nullable', 'date'],
        ]);

        if ($ticket->isClosed()) {
            return back()->withErrors(['ticket' => 'Tiket sudah ditutup. Buka kembali dulu untuk mengubahnya.']);
        }

        $changes = $this->changes($ticket, $data);

        $ticket->update($data);

        if ($changes !== []) {
            $this->log->log('ticket.updated', "Memperbarui tiket {$ticket->number}", $ticket, ['changes' => $changes]);
        }

        return back()->with('success', 'Tiket diperbarui.');
    }

    public function assign(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize(Permission::AssignTickets->value);

        $data = $request->validate([
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:512'],
        ], [], ['assigned_to' => 'operator']);

        try {
            $this->tickets->assign(
                $ticket,
                $data['assigned_to'] ? User::find($data['assigned_to']) : null,
                $request->user(),
                $data['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['assign' => $e->getMessage()]);
        }

        return back()->with('success', 'Penugasan tiket diperbarui.');
    }

    /** Add one follow-up. Never replaces the previous ones. */
    public function followUp(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize(Permission::HandleTickets->value);
        $this->guardVisibility($request->user(), $ticket);

        $data = $request->validate([
            'action' => ['required', 'string', 'max:32'],
            'channel_used' => ['nullable', 'string', 'max:24'],
            'response_text' => ['nullable', 'string', 'max:5000'],
            'outcome' => ['nullable', 'string', 'max:24'],
            'status' => ['nullable', 'string', 'max:24'],
            'next_action_at' => ['nullable', 'date'],
            'attachment' => ['nullable', 'file', 'max:10240'],

            // Free-form extra facts, sent as parallel key/value inputs.
            'extra_key' => ['nullable', 'array'],
            'extra_key.*' => ['nullable', 'string', 'max:64'],
            'extra_value' => ['nullable', 'array'],
            'extra_value.*' => ['nullable', 'string', 'max:512'],
        ], [], [
            'action' => 'tindakan',
            'response_text' => 'catatan',
        ]);

        $data['additional_data'] = $this->pairs($request);

        if ($file = $request->file('attachment')) {
            $data['attachment_path'] = $file->store('follow-ups', 'public');
            $data['attachment_name'] = $file->getClientOriginalName();
        }

        try {
            $this->tickets->addFollowUp($ticket, $data, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['follow_up' => $e->getMessage()]);
        }

        return back()->with('success', 'Follow up tersimpan.');
    }

    /** Add or correct one TiketDetail row (keyed by NIM). */
    public function storeDetail(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize(Permission::HandleTickets->value);
        $this->guardVisibility($request->user(), $ticket);

        $data = $request->validate([
            'nim' => ['required', 'string', 'max:32'],
            'nama' => ['nullable', 'string', 'max:255'],
            'fakultas' => ['nullable', 'string', 'max:255'],
            'prodi' => ['nullable', 'string', 'max:255'],
            'provinsi' => ['nullable', 'string', 'max:128'],
            'kabupaten' => ['nullable', 'string', 'max:128'],
            'kecamatan' => ['nullable', 'string', 'max:128'],
            'kelurahan' => ['nullable', 'string', 'max:128'],
            'no_hp' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'catatan' => ['nullable', 'string', 'max:2000'],
        ], [], ['nim' => 'NIM']);

        try {
            $detail = $this->tickets->saveDetail($ticket, $data, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['detail' => $e->getMessage()])->withInput();
        }

        return back()->with(
            'success',
            $detail->student_id
                ? "Data mahasiswa {$detail->nim} tersimpan dan tertaut ke data import."
                : "Data mahasiswa {$detail->nim} tersimpan. NIM ini belum ada di data import.",
        );
    }

    public function destroyDetail(Request $request, Ticket $ticket, TicketDetail $detail): RedirectResponse
    {
        $this->authorize(Permission::HandleTickets->value);
        $this->guardVisibility($request->user(), $ticket);

        // Guard against a detail id from another ticket being posted here.
        if ($detail->ticket_id !== $ticket->id) {
            abort(404);
        }

        try {
            $this->tickets->removeDetail($ticket, $detail, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['detail' => $e->getMessage()]);
        }

        return back()->with('success', 'Data mahasiswa dihapus dari tiket.');
    }

    /** Netral ⇄ Lead. */
    public function flag(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize(Permission::HandleTickets->value);
        $this->guardVisibility($request->user(), $ticket);

        $data = $request->validate(['flag' => ['required', 'string', 'max:16']]);

        $flag = TicketFlag::tryFrom($data['flag']);

        if ($flag === null) {
            return back()->withErrors(['flag' => 'Tanda tiket tidak dikenali.']);
        }

        $this->tickets->changeFlag($ticket, $flag, $request->user());

        return back()->with('success', "Tiket ditandai sebagai {$flag->label()}.");
    }

    public function status(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize(Permission::HandleTickets->value);
        $this->guardVisibility($request->user(), $ticket);

        $data = $request->validate([
            'status' => ['required', 'string', 'max:24'],
        ]);

        $status = TicketStatus::tryFrom($data['status']);

        if ($status === null) {
            return back()->withErrors(['status' => 'Status tidak dikenali.']);
        }

        // Closing needs a resolution, so it has its own route and its own form.
        if ($status === TicketStatus::Closed) {
            return back()->withErrors(['status' => 'Gunakan tombol Tutup Tiket agar resolusi ikut tersimpan.']);
        }

        try {
            $this->tickets->changeStatus($ticket, $status, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('success', 'Status tiket diperbarui.');
    }

    public function close(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize(Permission::CloseTickets->value);
        $this->guardVisibility($request->user(), $ticket);

        $data = $request->validate([
            'resolution_note' => ['required', 'string', 'min:5', 'max:5000'],
        ], [], ['resolution_note' => 'hasil penyelesaian']);

        $this->tickets->close($ticket, $data['resolution_note'], $request->user());

        return back()->with('success', "Tiket {$ticket->number} ditutup.");
    }

    public function reopen(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize(Permission::CloseTickets->value);
        $this->guardVisibility($request->user(), $ticket);

        $this->tickets->reopen($ticket, $request->user());

        return back()->with('success', 'Tiket dibuka kembali.');
    }

    public function export(Request $request, Exporter $exporter)
    {
        $this->authorize(Permission::ExportData->value);

        $query = Ticket::query()
            ->visibleTo($request->user())
            ->filtered($this->filters($request))
            ->with(['assignee:id,name', 'category:id,name', 'subCategory:id,name']);

        $this->log->log('ticket.exported', 'Export data tiket', null, [
            'format' => $request->input('format', 'xlsx'),
            'filters' => array_filter($this->filters($request)),
        ]);

        return $exporter->download(
            format: (string) $request->input('format', 'xlsx'),
            filename: 'tiket',
            columns: [
                'number' => 'Nomor',
                'created_at' => 'Dibuat',
                'source' => 'Sumber',
                'subject' => 'Judul',
                'category' => 'Kategori',
                'sub_category' => 'Sub Kategori',
                'status' => 'Status',
                'priority' => 'Prioritas',
                'flag' => 'Flag',
                'requester_name' => 'Nama',
                'requester_nim' => 'NIM',
                'requester_phone' => 'No HP',
                'operator' => 'Operator',
                'follow_up_count' => 'Jumlah Follow Up',
                'closed_at' => 'Ditutup',
                'resolution_note' => 'Resolusi',
            ],
            query: $query,
            mapper: fn (Ticket $t) => [
                $t->number, $t->created_at, $t->source, $t->subject,
                $t->category?->name, $t->subCategory?->name,
                $t->status, $t->priority, $t->flag,
                $t->requester_name, $t->requester_nim, $t->requester_phone,
                $t->assignee?->name, $t->follow_up_count, $t->closed_at, $t->resolution_note,
            ],
        );
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    /**
     * Turns the parallel extra_key[]/extra_value[] inputs into a map, dropping
     * rows where either side is blank.
     *
     * @return array<string, string>
     */
    private function pairs(Request $request): array
    {
        $keys = (array) $request->input('extra_key', []);
        $values = (array) $request->input('extra_value', []);
        $pairs = [];

        foreach ($keys as $index => $key) {
            $key = trim((string) $key);
            $value = trim((string) ($values[$index] ?? ''));

            if ($key !== '' && $value !== '') {
                $pairs[$key] = $value;
            }
        }

        return $pairs;
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->only(
            'q', 'status', 'priority', 'source', 'category', 'operator', 'flag',
            // Only meaningful for tickets raised from the student import; the
            // list hides these selects until that source is chosen.
            ...StudentStats::REGION_LEVELS,
        );
    }

    private function guardVisibility(User $user, Ticket $ticket): void
    {
        if ($user->hasPermission(Permission::ViewAllTickets)) {
            return;
        }

        if ($ticket->assigned_to !== $user->id && $ticket->created_by !== $user->id) {
            abort(403, 'Tiket ini bukan tanggung jawab Anda.');
        }
    }

    /** @return array<string, int> */
    private function stats(User $user): array
    {
        $counts = Ticket::query()
            ->visibleTo($user)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $get = fn (TicketStatus $s) => (int) ($counts[$s->value] ?? 0);

        return [
            'total' => (int) $counts->sum(),
            'open' => $get(TicketStatus::Open),
            'assigned' => $get(TicketStatus::Assigned),
            'in_progress' => $get(TicketStatus::InProgress),
            'follow_up' => $get(TicketStatus::FollowUp),
            'resolved' => $get(TicketStatus::Resolved),
            'closed' => $get(TicketStatus::Closed),
            'mine' => Ticket::where('assigned_to', $user->id)->open()->count(),
        ];
    }

    /**
     * Everyone a ticket can be handed to. Operator Follow Up is included: it
     * is the role that exists only to work tickets assigned to it.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function operators()
    {
        $roles = array_map(fn (RoleName $r) => $r->value, [
            RoleName::FollowUp, RoleName::Operator, RoleName::Pic, RoleName::Manager, RoleName::SuperAdmin,
        ]);

        return User::query()
            ->active()
            ->whereHas('role', fn ($q) => $q->whereIn('name', $roles))
            ->with('role:id,name,label')
            ->orderBy('name')
            ->get(['id', 'name', 'role_id'])
            // Grouped by role in the dropdown, in the order listed above.
            ->sortBy(fn (User $u) => array_search($u->role?->name?->value, $roles, true))
            ->values();
    }
}
