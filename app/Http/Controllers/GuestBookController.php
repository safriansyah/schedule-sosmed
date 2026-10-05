<?php

namespace App\Http\Controllers;

use App\Enums\GuestBookService;
use App\Enums\GuestBookStatus;
use App\Enums\Permission;
use App\Models\GuestBookEntry;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Tickets\TicketService;
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
            'services' => GuestBookService::options(),
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
            'status' => ['required', Rule::in(array_map(fn ($s) => $s->value, GuestBookStatus::settable()))],
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

    /** @return array<string, mixed> */
    private function listData(Request $request): array
    {
        $date = $this->date($request);
        $filter = $request->input('filter', 'active');

        $query = GuestBookEntry::query()
            ->whereDate('queue_date', $date)
            ->with(['ticket:id,number', 'handler:id,name']);

        match ($filter) {
            'active' => $query->active(),
            'finished' => $query->whereNotIn('status', array_map(fn ($s) => $s->value, GuestBookStatus::active())),
            default => null,
        };

        $all = GuestBookEntry::whereDate('queue_date', $date);

        return [
            'entries' => $query->orderBy('queue_number')->get(),
            'date' => $date,
            'filter' => in_array($filter, ['active', 'finished', 'all'], true) ? $filter : 'active',
            'counts' => [
                'total' => (clone $all)->count(),
                'waiting' => (clone $all)->where('status', GuestBookStatus::Waiting->value)->count(),
                'serving' => (clone $all)->whereIn('status', [GuestBookStatus::Called->value, GuestBookStatus::Serving->value])->count(),
                'ticketed' => (clone $all)->where('status', GuestBookStatus::Ticketed->value)->count(),
            ],
        ];
    }

    private function date(Request $request): Carbon
    {
        try {
            return $request->filled('date')
                ? Carbon::createFromFormat('Y-m-d', $request->input('date'), 'Asia/Jakarta')->startOfDay()
                : Carbon::now('Asia/Jakarta')->startOfDay();
        } catch (\Throwable) {
            return Carbon::now('Asia/Jakarta')->startOfDay();
        }
    }
}
