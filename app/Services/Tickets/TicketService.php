<?php

namespace App\Services\Tickets;

use App\Enums\AssignmentStatus;
use App\Enums\FollowUpStatus;
use App\Enums\GuestBookStatus;
use App\Enums\InteractionStatus;
use App\Enums\TicketFlag;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Models\AccountMedia;
use App\Models\FollowUp;
use App\Models\GuestBookEntry;
use App\Models\Interaction;
use App\Models\Student;
use App\Models\TicketDetail;
use App\Models\Ticket;
use App\Models\TicketAssignment;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Everything that happens to a ticket: birth, hand-over, follow-up, closure.
 *
 * Controllers stay thin because these operations each touch several tables
 * (ticket + assignment history + audit log + sometimes the student row) and
 * must stay consistent whichever screen triggered them.
 */
class TicketService
{
    public function __construct(private readonly ActivityLogger $log) {}

    /* -----------------------------------------------------------------
     | Creating
     * ----------------------------------------------------------------- */

    /**
     * "Add to Ticket" on an Instagram comment.
     *
     * The comment's details are COPIED onto the ticket, not merely linked.
     * Interactions are re-synced from an undocumented third-party viewer and
     * can be rewritten or disappear; the ticket has to keep reading correctly
     * regardless, so it owns its own snapshot of what was said.
     *
     * Idempotent: a comment already turned into a ticket returns that ticket
     * instead of making a second one, so a double-click costs nothing.
     */
    public function createFromInteraction(Interaction $interaction, User $actor, array $overrides = []): Ticket
    {
        if ($existing = Ticket::where('interaction_id', $interaction->getKey())->first()) {
            return $existing;
        }

        $media = $interaction->source instanceof AccountMedia ? $interaction->source : null;
        $contact = $interaction->contact;

        $ticket = Ticket::createWithNumber([
            'source' => TicketSource::fromChannel($interaction->channel)->value,
            'subject' => $overrides['subject'] ?? $this->subjectFrom($interaction),
            'description' => $overrides['description'] ?? $interaction->text,
            'category_id' => $overrides['category_id'] ?? null,
            'sub_category_id' => $overrides['sub_category_id'] ?? null,
            'status' => TicketStatus::Open->value,
            'priority' => $overrides['priority'] ?? ($interaction->is_urgent ? 'urgent' : 'normal'),
            // Seeded from the classifier's lead score, then editable by hand.
            'flag' => $overrides['flag'] ?? TicketFlag::fromLeadPotential($interaction->lead_potential)->value,

            'contact_id' => $contact?->getKey(),
            'requester_name' => $overrides['requester_name'] ?? ($contact?->full_name ?: $interaction->author_name),
            'requester_phone' => $overrides['requester_phone'] ?? $contact?->phone_e164,
            'requester_email' => $overrides['requester_email'] ?? $contact?->email,

            // The reference block the brief asks to show on the ticket.
            'interaction_id' => $interaction->getKey(),
            'source_channel' => $interaction->channel->value,
            'source_external_id' => $interaction->external_id,
            'source_username' => $interaction->author_handle,
            // pengirimpesanid — stable across handle changes.
            'source_sender_id' => $interaction->author_external_id,
            'source_text' => $interaction->text,
            'source_post_id' => $media?->external_id,
            'source_post_url' => $media?->permalink,
            'source_created_at' => $interaction->occurred_at,

            'created_by' => $actor->id,
        ]);

        // The comment is demonstrably being handled now, so it leaves the
        // inbox queue instead of greeting the operator again tomorrow.
        $ticket->setRelation('interaction', $interaction);
        $this->syncInteractionStatus($ticket, $actor);

        $this->log->log(
            'ticket.created',
            "Membuat tiket {$ticket->number} dari komentar @{$interaction->author_handle}",
            $ticket,
            ['source' => $interaction->channel->value, 'interaction_id' => $interaction->getKey()],
        );

        return $ticket;
    }

    /**
     * "Add Ticket" on a Buku Tamu / Antrian entry.
     *
     * Idempotent like createFromInteraction(), and safe against a double
     * click arriving as two parallel requests: the entry row is locked, so the
     * second request waits, then finds the ticket the first one made. The
     * unique index on guest_book_entries.ticket_id backs this up in the
     * database itself.
     *
     * The visitor's answers are copied onto the ticket; the entry keeps its
     * own record and is marked Ticketed, which takes it off the monitor.
     */
    public function createFromGuestBook(GuestBookEntry $entry, User $actor, ?User $assignee = null): Ticket
    {
        $ticket = DB::transaction(function () use ($entry, $actor) {
            $locked = GuestBookEntry::whereKey($entry->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->ticket_id && ($existing = Ticket::find($locked->ticket_id))) {
                return $existing;
            }

            // A NIM that matches the imported list links the ticket to that
            // student, so it shows up on their record like any other ticket.
            $student = $locked->nim ? Student::where('nim', $locked->nim)->first() : null;

            $ticket = Ticket::createWithNumber([
                'source' => TicketSource::GuestBook->value,
                'subject' => "Antrian {$locked->displayNumber()} — {$locked->service->label()}",
                'description' => $locked->description,
                'status' => TicketStatus::Open->value,
                'priority' => 'normal',
                'flag' => TicketFlag::Netral->value,
                'student_id' => $student?->id,
                'requester_name' => $locked->name,
                'requester_nim' => $locked->nim,
                'requester_phone' => $locked->phone,
                'source_created_at' => $locked->created_at,
                'extra' => [
                    'guest_book' => [
                        'queue_date' => $locked->queue_date->toDateString(),
                        'queue_number' => $locked->displayNumber(),
                        'whatsapp' => $locked->whatsapp,
                        'gender' => $locked->gender->label(),
                        'service' => $locked->service->label(),
                    ],
                ],
                'created_by' => $actor->id,
            ]);

            $locked->forceFill([
                'ticket_id' => $ticket->id,
                'status' => GuestBookStatus::Ticketed,
                'handled_by' => $actor->id,
                'finished_at' => now(),
            ])->save();

            $this->log->log(
                'ticket.created',
                "Membuat tiket {$ticket->number} dari antrian {$locked->displayNumber()} ({$locked->name})",
                $ticket,
                ['source' => TicketSource::GuestBook->value, 'guest_book_entry_id' => $locked->id],
            );

            return $ticket;
        });

        if ($assignee && $ticket->assigned_to === null) {
            $ticket = $this->assign($ticket, $assignee, $actor);
        }

        $entry->refresh();

        return $ticket;
    }

    /**
     * A ticket typed in by hand, or raised against an imported student.
     *
     * `number_format` picks which of the admin's numbering formats to use
     * (TKU, TKB, …). Left out, the default format applies — which is what
     * every automatic path does.
     */
    public function createManual(array $data, User $actor): Ticket
    {
        $student = isset($data['student_id']) ? Student::find($data['student_id']) : null;

        // A student's details win over anything typed alongside them: the
        // imported record is the authoritative one.
        if ($student) {
            $data['requester_name'] ??= $student->nama;
            $data['requester_nim'] ??= $student->nim;
            $data['requester_nac'] ??= $student->nac;
            $data['requester_phone'] ??= $student->no_hp_raw ?: $student->no_hp;
            $data['requester_email'] ??= $student->email;
        }

        $ticket = Ticket::createWithNumber([
            'source' => $data['source'] ?? TicketSource::Manual->value,
            'subject' => $data['subject'],
            'description' => $data['description'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'sub_category_id' => $data['sub_category_id'] ?? null,
            'status' => TicketStatus::Open->value,
            'priority' => $data['priority'] ?? 'normal',
            'flag' => $data['flag'] ?? TicketFlag::Netral->value,
            'student_id' => $student?->id,
            'contact_id' => $data['contact_id'] ?? null,
            'requester_name' => $data['requester_name'] ?? null,
            'requester_nim' => $data['requester_nim'] ?? null,
            'requester_nac' => $data['requester_nac'] ?? null,
            'requester_phone' => $data['requester_phone'] ?? null,
            'requester_email' => $data['requester_email'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'attachment_path' => $data['attachment_path'] ?? null,
            'attachment_name' => $data['attachment_name'] ?? null,
            'created_by' => $actor->id,
        ], $data['number_format'] ?? null);

        $this->log->log('ticket.created', "Membuat tiket {$ticket->number} secara manual", $ticket, [
            'source' => $ticket->source->value,
        ]);

        if (isset($data['assigned_to'])) {
            $assignee = User::find($data['assigned_to']);

            if ($assignee) {
                $this->assign($ticket, $assignee, $actor);
            }
        }

        return $ticket;
    }

    /* -----------------------------------------------------------------
     | Handling
     * ----------------------------------------------------------------- */

    /** Hand a ticket to someone, recording who held it before. */
    public function assign(Ticket $ticket, ?User $assignee, User $actor, ?string $note = null): Ticket
    {
        $this->guardOpen($ticket);

        $previous = $ticket->assigned_to;

        if ($previous === $assignee?->id) {
            return $ticket;
        }

        DB::transaction(function () use ($ticket, $assignee, $actor, $note, $previous) {
            $ticket->forceFill([
                'assigned_to' => $assignee?->id,
                'assigned_at' => $assignee ? now() : null,
                // Moving a ticket out of Open reflects that someone now owns
                // it; a ticket already in progress keeps its status.
                'status' => $assignee && $ticket->status === TicketStatus::Open
                    ? TicketStatus::Assigned->value
                    : $ticket->status->value,
            ])->save();

            TicketAssignment::create([
                'ticket_id' => $ticket->id,
                'from_user_id' => $previous,
                'to_user_id' => $assignee?->id,
                'assigned_by' => $actor->id,
                'note' => $note,
            ]);
        });

        $this->log->log(
            'ticket.assigned',
            $assignee
                ? "Tiket {$ticket->number} ditugaskan ke {$assignee->name}"
                : "Penugasan tiket {$ticket->number} dilepas",
            $ticket,
            ['from' => $previous, 'to' => $assignee?->id],
        );

        return $ticket->refresh();
    }

    /**
     * Record one follow-up.
     *
     * Appends — never replaces. The previous touches are untouched, which is
     * the whole reason follow-ups live in their own table.
     *
     * `additional_data` is the free-form "what I learned on the call" note.
     * Anything in it that the system actually tracks (a new phone number, a
     * new email) is ALSO written onto the ticket and its student, with the old
     * value kept in the audit log — so the record stays current without the
     * history being rewritten.
     */
    public function addFollowUp(Ticket $ticket, array $data, User $actor): FollowUp
    {
        $this->guardOpen($ticket);

        // The operator picks from the short vocabulary (New / Assigned /
        // onProses); the ticket's own six-state status is derived from it here,
        // in one place, so the two can never disagree.
        $followUpStatus = FollowUpStatus::tryFrom((string) ($data['status'] ?? ''))
            ?? FollowUpStatus::OnProses;

        return DB::transaction(function () use ($ticket, $data, $actor, $followUpStatus) {
            $followUp = $ticket->followUps()->create([
                'user_id' => $actor->id,
                'channel_used' => $data['channel_used'] ?? null,
                'action' => $data['action'],
                'response_text' => $data['response_text'] ?? null,
                'outcome' => $data['outcome'] ?? null,
                'next_action_at' => $data['next_action_at'] ?? null,
                'status_after' => $followUpStatus->value,
                'additional_data' => $data['additional_data'] ?? null,
                'attachment_path' => $data['attachment_path'] ?? null,
                'attachment_name' => $data['attachment_name'] ?? null,
            ]);

            $ticket->forceFill([
                'follow_up_count' => $ticket->followUps()->count(),
                'last_follow_up_at' => now(),
                'first_response_at' => $ticket->first_response_at ?? now(),
                'status' => $followUpStatus->toTicketStatus()->value,
            ])->save();

            $this->applyAdditionalData($ticket, $data['additional_data'] ?? [], $actor);
            $this->syncStudentStatus($ticket);

            $this->log->log(
                'ticket.followed_up',
                "Follow Up #{$ticket->follow_up_count} pada tiket {$ticket->number}",
                $ticket,
                array_filter([
                    'action' => $data['action'] ?? null,
                    'outcome' => $data['outcome'] ?? null,
                    'channel' => $data['channel_used'] ?? null,
                ]),
            );

            return $followUp;
        });
    }

    /* -----------------------------------------------------------------
     | TiketDetail
     * ----------------------------------------------------------------- */

    /**
     * Record (or update) the student this ticket turned out to be about.
     *
     * Keyed on (ticket, NIM), so calling it twice with the same NIM corrects
     * the row instead of adding a second one, and a ticket that covers two
     * people simply gets two rows.
     *
     * When the NIM is one we imported, the blanks are filled from that student
     * record and the two are linked — without overwriting anything the
     * operator typed, because they are the one who just spoke to the person.
     */
    public function saveDetail(Ticket $ticket, array $data, User $actor): TicketDetail
    {
        $this->guardOpen($ticket);

        $nim = trim((string) $data['nim']);

        $detail = TicketDetail::firstOrNew([
            'ticket_id' => $ticket->id,
            'nim' => $nim,
        ]);

        $isNew = ! $detail->exists;

        $detail->fill(array_filter([
            'nama' => $data['nama'] ?? null,
            'fakultas' => $data['fakultas'] ?? null,
            'prodi' => $data['prodi'] ?? null,
            'provinsi' => $data['provinsi'] ?? null,
            'kabupaten' => $data['kabupaten'] ?? null,
            'kecamatan' => $data['kecamatan'] ?? null,
            'kelurahan' => $data['kelurahan'] ?? null,
            'no_hp' => $data['no_hp'] ?? null,
            'email' => $data['email'] ?? null,
            'catatan' => $data['catatan'] ?? null,
        ], fn ($v) => filled($v)));

        $detail->created_by ??= $actor->id;

        if ($student = Student::where('nim', $nim)->first()) {
            $detail->fillFromStudent($student);

            // Link the ticket to the student too, so the student's page shows
            // this case and the assignment status can follow it.
            $ticket->student_id ??= $student->id;
        }

        $detail->ticket_id = $ticket->id;
        $detail->nim = $nim;
        $detail->save();

        // Mirror onto the ticket's own requester fields so list screens and
        // exports keep working without joining ticket_details.
        $ticket->forceFill(array_filter([
            'requester_nim' => $ticket->requester_nim ?: $nim,
            'requester_name' => $ticket->requester_name ?: $detail->nama,
            'requester_phone' => $ticket->requester_phone ?: $detail->no_hp,
            'requester_email' => $ticket->requester_email ?: $detail->email,
            'student_id' => $ticket->student_id,
        ], fn ($v) => filled($v)))->save();

        $this->log->log(
            $isNew ? 'ticket.detail_added' : 'ticket.detail_updated',
            ($isNew ? 'Menambah' : 'Memperbarui')." data mahasiswa {$nim} pada tiket {$ticket->number}",
            $ticket,
            ['nim' => $nim, 'student_id' => $detail->student_id],
        );

        return $detail;
    }

    public function removeDetail(Ticket $ticket, TicketDetail $detail, User $actor): void
    {
        $this->guardOpen($ticket);

        $nim = $detail->nim;
        $detail->delete();

        $this->log->log(
            'ticket.detail_removed',
            "Menghapus data mahasiswa {$nim} dari tiket {$ticket->number}",
            $ticket,
            ['nim' => $nim],
        );
    }

    /** Netral ⇄ Lead. Kept apart from status on purpose — see TicketFlag. */
    public function changeFlag(Ticket $ticket, TicketFlag $flag, User $actor): Ticket
    {
        $from = $ticket->flag;

        if ($from === $flag) {
            return $ticket;
        }

        $ticket->forceFill(['flag' => $flag->value])->save();

        $this->log->log(
            'ticket.flag_changed',
            "Tanda tiket {$ticket->number}: {$from->label()} → {$flag->label()}",
            $ticket,
            ['from' => $from->value, 'to' => $flag->value],
        );

        return $ticket;
    }

    /** Move a ticket between statuses without recording a follow-up. */
    public function changeStatus(Ticket $ticket, TicketStatus $status, User $actor): Ticket
    {
        if ($status === TicketStatus::Closed) {
            throw new RuntimeException('Gunakan close() agar resolusi tiket ikut tersimpan.');
        }

        $this->guardOpen($ticket);

        $from = $ticket->status;

        if ($from === $status) {
            return $ticket;
        }

        $ticket->forceFill(['status' => $status->value])->save();
        $this->syncStudentStatus($ticket);
        $this->syncInteractionStatus($ticket, $actor);

        $this->log->log(
            'ticket.status_changed',
            "Status tiket {$ticket->number}: {$from->label()} → {$status->label()}",
            $ticket,
            ['from' => $from->value, 'to' => $status->value],
        );

        return $ticket;
    }

    /**
     * Close a ticket with its resolution.
     *
     * The ticket is not deleted or hidden: it keeps every follow-up and stays
     * readable in the list under the Closed filter. `closed_by`, `closed_at`
     * and `resolution_note` are what make it auditable afterwards.
     */
    public function close(Ticket $ticket, string $resolution, User $actor): Ticket
    {
        if ($ticket->isClosed()) {
            return $ticket;
        }

        DB::transaction(function () use ($ticket, $resolution, $actor) {
            $ticket->forceFill([
                'status' => TicketStatus::Closed->value,
                'resolution_note' => $resolution,
                'closed_by' => $actor->id,
                'closed_at' => now(),
            ])->save();

            $this->syncStudentStatus($ticket);
            $this->syncInteractionStatus($ticket, $actor);
        });

        $this->log->log('ticket.closed', "Menutup tiket {$ticket->number}", $ticket, [
            'resolution' => mb_substr($resolution, 0, 300),
        ]);

        return $ticket;
    }

    /** Re-open a closed ticket — the resolution is kept for the record. */
    public function reopen(Ticket $ticket, User $actor): Ticket
    {
        if (! $ticket->isClosed()) {
            return $ticket;
        }

        $ticket->forceFill([
            'status' => $ticket->assigned_to ? TicketStatus::Assigned->value : TicketStatus::Open->value,
            'closed_by' => null,
            'closed_at' => null,
        ])->save();

        $this->syncInteractionStatus($ticket, $actor);

        $this->log->log('ticket.reopened', "Membuka kembali tiket {$ticket->number}", $ticket);

        return $ticket;
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    /**
     * Copies recognised facts from a follow-up onto the ticket and its
     * student, logging the old value of anything it overwrites.
     *
     * Only these keys are acted on. Everything else the operator typed stays
     * on the follow-up as a note, which is the right place for it.
     *
     * @param  array<string, mixed>  $additional
     */
    private function applyAdditionalData(Ticket $ticket, array $additional, User $actor): void
    {
        $known = [
            'no_hp' => 'requester_phone',
            'phone' => 'requester_phone',
            'email' => 'requester_email',
            'nama' => 'requester_name',
            'nim' => 'requester_nim',
            'nac' => 'requester_nac',
        ];

        $changes = [];

        foreach ($additional as $key => $value) {
            $column = $known[strtolower(trim((string) $key))] ?? null;

            if ($column === null || blank($value)) {
                continue;
            }

            $old = $ticket->{$column};

            if ((string) $old === (string) $value) {
                continue;
            }

            $changes[$column] = ['from' => $old, 'to' => $value];
            $ticket->{$column} = $value;
        }

        if ($changes === []) {
            return;
        }

        $ticket->save();

        // Keep the student record in step, so the next operator sees the new
        // number without reading the follow-up history.
        if ($student = $ticket->student) {
            $student->forceFill(array_filter([
                'nama' => $ticket->requester_name,
                'no_hp_raw' => $ticket->requester_phone,
                'email' => $ticket->requester_email,
            ]))->save();
        }

        $this->log->log(
            'ticket.data_updated',
            "Data tambahan dari follow up memperbarui tiket {$ticket->number}",
            $ticket,
            ['changes' => $changes],
        );
    }

    /**
     * Mirrors ticket progress back onto the comment it came from.
     *
     * Without this a comment that has become a ticket stays in the inbox
     * queue for ever: the operator meets the same angry comment every morning
     * and the red badge never falls, so the queue stops meaning anything.
     *
     * The rule is one-directional and conservative — it only ever moves a
     * comment FORWARD. A comment an operator already replied to is left where
     * it is, because "replied" says more than "in progress" does.
     */
    private function syncInteractionStatus(Ticket $ticket, User $actor): void
    {
        $interaction = $ticket->interaction;

        if ($interaction === null) {
            return;
        }

        $finished = in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true);

        if ($finished) {
            $interaction->forceFill([
                'status' => InteractionStatus::Done->value,
                'resolved_at' => $interaction->resolved_at ?? now(),
                'resolved_by' => $interaction->resolved_by ?? $actor->id,
            ])->save();

            return;
        }

        // Re-opened, or just created: the case is live again.
        if ($interaction->status === InteractionStatus::Done) {
            $interaction->forceFill([
                'status' => InteractionStatus::InProgress->value,
                'resolved_at' => null,
                'resolved_by' => null,
            ])->save();

            return;
        }

        // A brand-new comment is now demonstrably being worked on. Anything
        // further along (replied, ignored) is left untouched.
        if ($interaction->status === InteractionStatus::New) {
            $interaction->forceFill([
                'status' => InteractionStatus::InProgress->value,
                'first_response_at' => $interaction->first_response_at ?? now(),
                'assigned_to' => $interaction->assigned_to ?? $ticket->assigned_to ?? $actor->id,
            ])->save();
        }
    }

    /**
     * Mirrors ticket progress onto the student's assignment status, so the
     * student list shows where a person stands without joining tickets.
     */
    private function syncStudentStatus(Ticket $ticket): void
    {
        $student = $ticket->student;

        if ($student === null || $student->assigned_to === null) {
            return;
        }

        $status = match ($ticket->status) {
            TicketStatus::Closed => AssignmentStatus::Closed,
            TicketStatus::Resolved => AssignmentStatus::Resolved,
            TicketStatus::FollowUp, TicketStatus::InProgress => AssignmentStatus::FollowUp,
            default => AssignmentStatus::Assigned,
        };

        if ($student->assignment_status !== $status) {
            $student->forceFill(['assignment_status' => $status->value])->save();
        }
    }

    private function guardOpen(Ticket $ticket): void
    {
        if ($ticket->isClosed()) {
            throw new RuntimeException('Tiket sudah ditutup. Buka kembali dulu sebelum mengubahnya.');
        }
    }

    private function subjectFrom(Interaction $interaction): string
    {
        $text = trim((string) $interaction->text);
        $handle = $interaction->author_handle ? '@'.$interaction->author_handle : 'Komentar';

        return $text === ''
            ? "{$handle} — perlu tindak lanjut"
            : mb_substr("{$handle}: {$text}", 0, 200);
    }
}
