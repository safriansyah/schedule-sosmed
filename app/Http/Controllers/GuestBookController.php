<?php

namespace App\Http\Controllers;

use App\Enums\GuestBookCompletion;
use App\Enums\GuestBookService;
use App\Enums\GuestBookStatus;
use App\Enums\Permission;
use App\Models\GuestBookEntry;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Tickets\TicketService;
use App\Support\Spreadsheet\ServiceLedgerWriter;
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
            // "Operator yang menyelesaikan": the front desk too, not only ticket handlers.
            'completers' => User::ticketHandlerOptions(User::GUEST_BOOK_COMPLETER_ROLES),
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
        ] + ($status === GuestBookStatus::Done ? [] : [
            // Back in the queue (or skipped): the old "Selesai" details no
            // longer describe this visit, and must not reach the ledger.
            'service_process' => null,
            'resolution' => null,
            'completed_by' => null,
            'completion_note' => null,
        ]))->save();

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
            'completed_by' => ['required', 'integer', User::ticketHandlerRule(User::GUEST_BOOK_COMPLETER_ROLES)],
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

    /**
     * The printed "Buku Besar Daftar Layanan" form, filled: unit, form code
     * and the masa registrasi the operator types in, then one row per visit
     * in the order they came — with the visitor's drawn paraf as a picture.
     *
     * Same search and type as the list on screen; the dates and the status
     * come from the download modal (Selesai by default — the ledger is a
     * record of visits that were served). Newest first, like every list.
     */
    public function export(Request $request)
    {
        $this->authorize(Permission::ManageGuestBook->value);

        $data = $request->validate([
            'masa_registrasi' => ['required', 'string', 'regex:/^\d{4}\.\d{1,2}$/'],
            'status' => ['nullable', Rule::in(array_keys(self::LEDGER_STATUSES))],
        ], [
            'masa_registrasi.required' => 'Isi masa registrasi, contoh 2026.1.',
            'masa_registrasi.regex' => 'Format masa registrasi: tahun titik angka, contoh 2026.1.',
        ]);

        [$from, $to] = $this->range($request);

        $this->log->log('guest_book.exported', "Export buku besar layanan {$from->toDateString()} s/d {$to->toDateString()} (masa {$data['masa_registrasi']})", null, [
            'filters' => array_filter($request->only('q', 'type', 'status')),
            'masa_registrasi' => $data['masa_registrasi'],
        ]);

        $status = $data['status'] ?? 'done';

        $rows = (function () use ($request, $status) {
            $number = 0;
            $query = $this->filteredQuery($request, withTab: false)
                ->with(['completer:id,name', 'handler:id,name', 'ticket:id,number'])
                ->when($status !== 'all', fn (Builder $q) => $q->whereIn('status', self::LEDGER_STATUSES[$status][1]));

            foreach ($query->lazy(500) as $e) {
                /** @var GuestBookEntry $e */
                $resolution = GuestBookCompletion::resolutionLabel($e->resolution)
                    ?? ($e->ticket ? 'Dijadikan tiket '.$e->ticket->number : null);

                yield [
                    'cells' => [
                        ++$number,
                        $e->queue_date->format('d/m/Y'),
                        $e->name.($e->nim ? ' / '.$e->nim : ''),
                        $e->service->label(),
                        GuestBookCompletion::processLabel($e->service_process) ?? '-',
                        $resolution ?? '-',
                        $e->finished_at?->timezone('Asia/Jakarta')->format('d/m/Y') ?? '-',
                        $e->name,
                        null, // the paraf picture
                        $e->completer?->name ?? $e->handler?->name ?? '-',
                    ],
                    'signature' => $e->signature_path && Storage::disk('local')->exists($e->signature_path)
                        ? Storage::disk('local')->path($e->signature_path)
                        : null,
                ];
            }
        })();

        $temp = tempnam(sys_get_temp_dir(), 'ledger_');
        @unlink($temp);
        $path = (new ServiceLedgerWriter)->write($temp.'.xlsx', [
            'unit' => self::LEDGER_UNIT,
            'period' => $data['masa_registrasi'],
            'code' => self::LEDGER_CODE,
            'code_date' => self::LEDGER_CODE_DATE,
        ], $rows);

        $name = 'buku-tamu-'.($from->equalTo($to) ? $from->format('Ymd') : $from->format('Ymd').'-sd-'.$to->format('Ymd'))
            .'-masa-'.$data['masa_registrasi'].'.xlsx';

        return response()
            ->download($path, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend();
    }

    /** Fixed parts of the printed form, filled in automatically. */
    public const LEDGER_UNIT = 'Pangkalpinang';

    public const LEDGER_CODE = 'JJ03-RK06-RII.2';

    public const LEDGER_CODE_DATE = '9 Agustus 2017';

    /** Status choices on the download modal: key => [label, statuses]. */
    public const LEDGER_STATUSES = [
        'done' => ['Selesai', ['done']],
        'finished' => ['Selesai & dijadikan tiket', ['done', 'ticketed']],
        'ticketed' => ['Dijadikan tiket', ['ticketed']],
        'all' => ['Semua status', []],
    ];

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

        // Terbaru dulu: tanggal terbaru, dan dalam satu hari nomor terakhir di atas.
        return $query->orderByDesc('queue_date')->orderByDesc('queue_number');
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
