<?php

namespace App\Http\Controllers;

use App\Enums\GuestBookCompletion;
use App\Enums\GuestBookService;
use App\Enums\GuestBookStatus;
use App\Enums\Permission;
use App\Models\GuestBookEntry;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Export\Exporter;
use App\Services\Tickets\TicketService;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Buku Tamu / Antrian — the operator's half: the day's queue, calling and
 * finishing visitors, and "Add Ticket".
 *
 * Every action answers in JSON for the page's fetch() calls, and the list
 * itself is re-fetched every few seconds, so two operators working the same
 * queue see each other's changes without reloading.
 */
class GuestBookController extends Controller
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly ActivityLogger $log,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize(Permission::ManageGuestBook->value);

        return view('guest-book.admin.index', $this->listData($request) + [
            'statuses' => collect(GuestBookStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all(),
            'types' => GuestBookService::types(),
            'processes' => GuestBookCompletion::processes(),
            'resolutions' => GuestBookCompletion::resolutions(),
            'operators' => User::ticketHandlerOptions(),
            'canAssign' => $request->user()->hasPermission(Permission::AssignTickets),
            'canTicket' => $request->user()->hasPermission(Permission::CreateTickets),
        ]);
    }

    /** The list alone, re-fetched by the page's poller. */
    public function rows(Request $request): View
    {
        $this->authorize(Permission::ManageGuestBook->value);

        return view('guest-book.admin.rows', $this->listData($request) + [
            'operators' => User::ticketHandlerOptions(),
            'canAssign' => $request->user()->hasPermission(Permission::AssignTickets),
            'canTicket' => $request->user()->hasPermission(Permission::CreateTickets),
        ]);
    }

    /** Panggil / Layani / Selesai / Lewati / kembali ke antrean. */
    public function status(Request $request, GuestBookEntry $entry): JsonResponse
    {
        $this->authorize(Permission::ManageGuestBook->value);

        $data = $request->validate([
            // Not "done": that goes through complete(), so a finished visit
            // always records how it was handled and by whom.
            'status' => ['required', Rule::in(array_map(
                fn ($s) => $s->value,
                array_filter(GuestBookStatus::settable(), fn ($s) => $s !== GuestBookStatus::Done),
            ))],
        ]);

        // A ticketed entry is history now; its state belongs to the ticket.
        if ($entry->hasTicket()) {
            return response()->json(['message' => 'Antrian ini sudah menjadi tiket dan tidak bisa diubah lagi.'], 422);
        }

        $status = GuestBookStatus::from($data['status']);

        $entry->forceFill([
            'status' => $status,
            'handled_by' => $request->user()->id,
            'called_at' => in_array($status, [GuestBookStatus::Called, GuestBookStatus::Serving], true)
                ? ($entry->called_at ?? now())
                : ($status === GuestBookStatus::Waiting ? null : $entry->called_at),
            'finished_at' => $status->isActive() ? null : now(),
        ])->save();

        $this->log->log(
            'guest_book.status',
            "Antrian {$entry->displayNumber()} ({$entry->name}) → {$status->label()}",
            $entry,
            ['status' => $status->value],
        );

        return response()->json([
            'message' => "Antrian {$entry->displayNumber()}: {$status->label()}.",
            'status' => $status->value,
        ]);
    }

    /**
     * "Add Ticket". Idempotent: pressing it twice — or two operators pressing
     * it at once — yields the one ticket, never two.
     */
    public function ticket(Request $request, GuestBookEntry $entry): JsonResponse
    {
        $this->authorize(Permission::ManageGuestBook->value);
        $this->authorize(Permission::CreateTickets->value);

        $data = $request->validate([
            'assigned_to' => ['nullable', 'integer', User::ticketHandlerRule()],
        ], [], ['assigned_to' => 'operator']);

        $hadTicket = $entry->hasTicket();

        // Only someone allowed to assign tickets may hand this one out.
        $assignee = ! empty($data['assigned_to']) && $request->user()->hasPermission(Permission::AssignTickets)
            ? User::find($data['assigned_to'])
            : null;

        $ticket = $this->tickets->createFromGuestBook($entry, $request->user(), $assignee);

        return response()->json([
            'message' => $hadTicket
                ? "Antrian ini sudah menjadi tiket {$ticket->number}."
                : "Tiket {$ticket->number} dibuat dari antrian {$entry->displayNumber()}.",
            'ticket' => $ticket->number,
            'url' => route('tickets.show', $ticket),
            'created' => ! $hadTicket,
        ]);
    }

    /** The drawn paraf. Private disk, so only staff see it, through here. */
    public function signature(GuestBookEntry $entry): StreamedResponse
    {
        abort_unless(
            request()->user()->hasPermission(Permission::ManageGuestBook)
                || request()->user()->hasPermission(Permission::ViewTickets),
            403,
        );

        abort_if(blank($entry->signature_path) || ! Storage::disk('local')->exists($entry->signature_path), 404);

        return Storage::disk('local')->response($entry->signature_path, "paraf-{$entry->displayNumber()}.png", [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * "Selesai", with what the form asks: how the service was carried out,
     * how it ended, and which operator finished it — not necessarily the
     * person at the keyboard.
     */
    public function complete(Request $request, GuestBookEntry $entry): JsonResponse
    {
        $this->authorize(Permission::ManageGuestBook->value);

        $data = $request->validate([
            'service_process' => ['required', Rule::in(array_keys(GuestBookCompletion::processes()))],
            'resolution' => ['required', Rule::in(array_keys(GuestBookCompletion::resolutions()))],
            'completed_by' => ['required', 'integer', User::ticketHandlerRule()],
            'completion_note' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'service_process' => 'proses layanan',
            'resolution' => 'penyelesaian',
            'completed_by' => 'operator',
            'completion_note' => 'catatan',
        ]);

        if ($entry->hasTicket()) {
            return response()->json(['message' => 'Antrian ini sudah menjadi tiket; selesaikan lewat tiketnya.'], 422);
        }

        $entry->forceFill([
            'status' => GuestBookStatus::Done,
            'service_process' => $data['service_process'],
            'resolution' => $data['resolution'],
            'completed_by' => $data['completed_by'],
            'completion_note' => filled($data['completion_note'] ?? null) ? trim($data['completion_note']) : null,
            'handled_by' => $request->user()->id,
            'called_at' => $entry->called_at ?? now(),
            'finished_at' => now(),
        ])->save();

        $operator = User::find($data['completed_by'])?->name;

        $this->log->log(
            'guest_book.completed',
            "Antrian {$entry->displayNumber()} ({$entry->name}) selesai — diselesaikan {$operator}",
            $entry,
            ['process' => $data['service_process'], 'resolution' => $data['resolution'], 'completed_by' => $data['completed_by']],
        );

        return response()->json([
            'message' => "Antrian {$entry->displayNumber()} selesai.",
            'entry' => $entry->fresh(['completer:id,name', 'handler:id,name', 'ticket:id,number'])->adminPayload(),
        ]);
    }

    /** Download what the list shows — same search, dates, type and tab. */
    public function export(Request $request, Exporter $exporter)
    {
        $this->authorize(Permission::ManageGuestBook->value);

        [$from, $to] = $this->range($request);

        $this->log->log('guest_book.exported', "Export buku tamu {$from->toDateString()} s/d {$to->toDateString()}", null, [
            'filters' => array_filter($request->only('q', 'type', 'filter')),
        ]);

        return $exporter->download(
            format: (string) $request->input('format', 'xlsx'),
            // The exporter stamps the download date onto the name itself.
            filename: $from->equalTo($to) ? 'buku-tamu' : 'buku-tamu-'.$from->format('Ymd').'-sd-'.$to->format('Ymd'),
            columns: [
                'date' => 'Tanggal',
                'number' => 'No. Antrian',
                'name' => 'Nama',
                'nim' => 'NIM',
                'gender' => 'Jenis Kelamin',
                'whatsapp' => 'No. WhatsApp',
                'phone' => 'No. HP',
                'type' => 'Jenis Kunjungan',
                'service' => 'Layanan / Keluhan',
                'description' => 'Deskripsi',
                'status' => 'Status',
                'registered' => 'Jam Daftar',
                'called' => 'Jam Dipanggil',
                'finished' => 'Selesai',
                'process' => 'Proses Layanan',
                'resolution' => 'Penyelesaian',
                'completed_by' => 'Operator yang Menyelesaikan',
                'note' => 'Catatan',
                'ticket' => 'Tiket',
            ],
            query: $this->filteredQuery($request)->with(['completer:id,name', 'ticket:id,number']),
            mapper: fn (GuestBookEntry $e) => [
                $e->queue_date->format('Y-m-d'),
                $e->displayNumber(),
                $e->name,
                $e->nim,
                $e->gender->label(),
                PhoneNumber::pretty($e->whatsapp) ?? $e->whatsapp,
                PhoneNumber::pretty($e->phone) ?? $e->phone,
                $e->service->typeLabel(),
                $e->service->label(),
                $e->description,
                $e->status->label(),
                $e->created_at->timezone('Asia/Jakarta')->format('H:i'),
                $e->called_at?->timezone('Asia/Jakarta')->format('H:i'),
                $e->finished_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
                GuestBookCompletion::processLabel($e->service_process),
                GuestBookCompletion::resolutionLabel($e->resolution),
                $e->completer?->name,
                $e->completion_note,
                $e->ticket?->number,
            ],
        );
    }

    /** More than a day's queue on one screen stops being useful; export instead. */
    private const LIST_LIMIT = 300;

    /** @return array<string, mixed> */
    private function listData(Request $request): array
    {
        [$from, $to] = $this->range($request);

        // Counted over the whole range and search, before the tab narrows
        // it, so the cards always add up to what the dates hold.
        $base = $this->filteredQuery($request, withTab: false);

        $entries = $this->filteredQuery($request)
            ->with(['ticket:id,number', 'handler:id,name', 'completer:id,name'])
            ->limit(self::LIST_LIMIT + 1)
            ->get();

        return [
            'entries' => $entries->take(self::LIST_LIMIT),
            'truncated' => $entries->count() > self::LIST_LIMIT,
            'from' => $from,
            'to' => $to,
            'filter' => in_array($request->input('filter'), ['active', 'finished', 'all'], true) ? $request->input('filter') : 'active',
            'counts' => [
                'total' => (clone $base)->count(),
                'waiting' => (clone $base)->where('status', GuestBookStatus::Waiting->value)->count(),
                'serving' => (clone $base)->whereIn('status', [GuestBookStatus::Called->value, GuestBookStatus::Serving->value])->count(),
                'done' => (clone $base)->where('status', GuestBookStatus::Done->value)->count(),
                'ticketed' => (clone $base)->where('status', GuestBookStatus::Ticketed->value)->count(),
            ],
        ];
    }

    /**
     * The list's query: date range, search, type and (optionally) the
     * Aktif / Selesai / Semua tab. Shared by the list and the export so the
     * file is exactly what is on screen.
     */
    private function filteredQuery(Request $request, bool $withTab = true): Builder
    {
        [$from, $to] = $this->range($request);

        $query = GuestBookEntry::query()
            ->whereBetween('queue_date', [$from->toDateString(), $to->toDateString()]);

        if (in_array($request->input('type'), array_keys(GuestBookService::types()), true)) {
            $query->whereIn('service', GuestBookService::valuesOf($request->input('type')));
        }

        if ($q = trim((string) $request->input('q'))) {
            $digits = preg_replace('/\D+/', '', $q);
            // Numbers are stored as 62..., people type 08...
            $phone = str_starts_with($digits, '0') ? '62'.substr($digits, 1) : $digits;

            $query->where(function (Builder $w) use ($q, $digits, $phone) {
                $w->where('name', 'like', "%{$q}%")
                    ->orWhere('nim', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");

                if ($digits !== '') {
                    $w->orWhere('whatsapp', 'like', "%{$phone}%")
                        ->orWhere('phone', 'like', "%{$phone}%");

                    // "7" or "007" finds queue number 7.
                    if (strlen($digits) <= 4) {
                        $w->orWhere('queue_number', (int) $digits);
                    }
                }
            });
        }

        if ($withTab) {
            match ($request->input('filter', 'active')) {
                'finished' => $query->whereNotIn('status', array_map(fn ($s) => $s->value, GuestBookStatus::active())),
                'all' => null,
                default => $query->active(),
            };
        }

        return $query->orderByDesc('queue_date')->orderBy('queue_number');
    }

    /**
     * Dari-sampai, both defaulting to today (WIB). A reversed range is
     * swapped rather than refused; a malformed date falls back to today.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $parse = function (?string $value): Carbon {
            try {
                return filled($value)
                    ? Carbon::createFromFormat('Y-m-d', $value, 'Asia/Jakarta')->startOfDay()
                    : Carbon::now('Asia/Jakarta')->startOfDay();
            } catch (\Throwable) {
                return Carbon::now('Asia/Jakarta')->startOfDay();
            }
        };

        // `date` is the old single-day parameter; still honoured.
        $from = $parse($request->input('from', $request->input('date')));
        $to = $parse($request->input('to', $request->input('date')));

        return $from->gt($to) ? [$to, $from] : [$from, $to];
    }
}
