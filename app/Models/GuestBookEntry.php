<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\GuestBookService;
use App\Enums\GuestBookStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One visitor in the Buku Tamu / Antrian. See the migration for the shape.
 *
 * Nothing here is mass-assignable from a request: the public form goes through
 * take(), which decides the number and the status itself, so a visitor cannot
 * pick their own queue number or arrive already "being served".
 */
class GuestBookEntry extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'queue_date' => 'date',
            'gender' => Gender::class,
            'service' => GuestBookService::class,
            'status' => GuestBookStatus::class,
            'called_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Records a visitor and gives them the next number of the day.
     *
     * The next number is read under a lock and the (date, number) pair is
     * unique, so two people pressing Simpan in the same instant cannot both
     * get 007. If they still collide, the loser simply tries again.
     *
     * @param  array{whatsapp: string, phone: string, name: string, nim: ?string, gender: string, service: string, description: ?string, signature_path: ?string, ip_address: ?string}  $data
     */
    public static function take(array $data): self
    {
        $today = Carbon::now('Asia/Jakarta')->toDateString();

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($data, $today) {
                    $last = static::where('queue_date', $today)->lockForUpdate()->max('queue_number');

                    $entry = new static;
                    $entry->forceFill($data + [
                        'queue_date' => $today,
                        'queue_number' => ((int) $last) + 1,
                        'status' => GuestBookStatus::Waiting,
                    ])->save();

                    return $entry;
                });
            } catch (QueryException $e) {
                // 23000 = integrity constraint: someone else took the number.
                if ($attempt >= 5 || $e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /** The operator who finished the service (chosen in the "Selesai" form). */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Everything the detail and "Selesai" modals show, as plain values — the
     * page builds its modals from this rather than from markup in each row.
     *
     * @return array<string, mixed>
     */
    public function adminPayload(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->displayNumber(),
            'date' => $this->queue_date->translatedFormat('d M Y'),
            'name' => $this->name,
            'nim' => $this->nim,
            'gender' => $this->gender->label(),
            'whatsapp' => \App\Support\PhoneNumber::pretty($this->whatsapp) ?? $this->whatsapp,
            'phone' => \App\Support\PhoneNumber::pretty($this->phone) ?? $this->phone,
            'type' => $this->service->typeLabel(),
            'service' => $this->service->label(),
            'description' => $this->description,
            'status' => $this->status->label(),
            'registered_at' => $this->created_at->timezone('Asia/Jakarta')->format('H:i'),
            'called_at' => $this->called_at?->timezone('Asia/Jakarta')->format('H:i'),
            'finished_at' => $this->finished_at?->timezone('Asia/Jakarta')->translatedFormat('d M Y, H:i'),
            'process' => \App\Enums\GuestBookCompletion::processLabel($this->service_process),
            'resolution' => \App\Enums\GuestBookCompletion::resolutionLabel($this->resolution),
            'completed_by' => $this->completer?->name,
            'completion_note' => $this->completion_note,
            'handler' => $this->handler?->name,
            'ticket' => $this->ticket?->number,
            'ticket_url' => $this->ticket ? route('tickets.show', $this->ticket) : null,
            'signature_url' => $this->signature_path ? route('guest-book.admin.signature', $this) : null,
            'complete_url' => route('guest-book.admin.complete', $this),
            'ticket_create_url' => route('guest-book.admin.ticket', $this),
        ];
    }

    /** Entries of today's queue (WIB). */
    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('queue_date', Carbon::now('Asia/Jakarta')->toDateString());
    }

    /** Still waiting, called or being served — what the monitor shows. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(fn ($s) => $s->value, GuestBookStatus::active()));
    }

    /**
     * The service as a single readable phrase: "Legalisir Ijazah",
     * "Keluhan: Permasalahan Nilai", "Layanan Lainnya". A bare "Lainnya"
     * would not say which of the two groups it came from.
     */
    public function serviceLabel(): string
    {
        return match (true) {
            $this->service === GuestBookService::LayananLainnya => 'Layanan Lainnya',
            $this->service->type() === GuestBookService::TYPE_COMPLAINT => 'Keluhan: '.$this->service->label(),
            default => $this->service->label(),
        };
    }

    /** "007" — what is printed on the screen and read out. */
    public function displayNumber(): string
    {
        return str_pad((string) $this->queue_number, 3, '0', STR_PAD_LEFT);
    }

    /**
     * The name as the public monitor shows it: first name plus initials
     * ("Ahmad F."). Enough to recognise yourself on a TV in the waiting room,
     * without broadcasting everyone's full name to the room.
     */
    public function publicName(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->name)) ?: [];
        $first = array_shift($parts) ?? '';
        $initials = implode('', array_map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)).'.', $parts));

        return trim($first.' '.$initials);
    }

    public function hasTicket(): bool
    {
        return $this->ticket_id !== null;
    }

    /**
     * What the public monitor receives — a whitelist, so the JSON can never
     * carry a phone number, NIM or note by accident.
     *
     * @return array<string, string>
     */
    public function monitorPayload(): array
    {
        return [
            'number' => $this->displayNumber(),
            'name' => $this->publicName(),
            'service' => $this->serviceLabel(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
        ];
    }
}
