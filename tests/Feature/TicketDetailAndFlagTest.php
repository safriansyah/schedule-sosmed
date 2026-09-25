<?php

/**
 * The refined data model: TiketDetail, flag (Netral | Lead), the four-state
 * follow-up status, and pengirimpesanid.
 */

use App\Enums\FollowUpStatus;
use App\Enums\TicketFlag;
use App\Enums\TicketStatus;
use App\Models\Student;
use App\Models\Ticket;
use App\Models\TicketDetail;
use App\Services\Tickets\TicketService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

function newTicket(array $data = []): Ticket
{
    return app(TicketService::class)->createManual(
        $data + ['subject' => 'Tiket uji detail'],
        admin(),
    );
}

/* -----------------------------------------------------------------
 | Flag
 * ----------------------------------------------------------------- */

it('starts a ticket on Netral and lets it be flagged as Lead', function () {
    $ticket = newTicket();

    expect($ticket->flag)->toBe(TicketFlag::Netral)
        ->and($ticket->isLead())->toBeFalse();

    app(TicketService::class)->changeFlag($ticket, TicketFlag::Lead, admin());
    $ticket->refresh();

    expect($ticket->flag)->toBe(TicketFlag::Lead)
        ->and($ticket->isLead())->toBeTrue();

    $logged = $ticket->activities()->where('action', 'ticket.flag_changed')->first();

    expect($logged)->not->toBeNull()
        ->and($logged->properties['from'])->toBe('netral')
        ->and($logged->properties['to'])->toBe('lead');
});

it('flags a comment-born ticket as Lead when the classifier scored it high', function () {
    $comment = instagramComment();
    $comment->forceFill(['lead_potential' => 80])->save();

    $ticket = app(TicketService::class)->createFromInteraction($comment, admin());

    expect($ticket->flag)->toBe(TicketFlag::Lead);
});

it('leaves a low-scoring comment on Netral', function () {
    $comment = instagramComment();
    $comment->forceFill(['lead_potential' => 10])->save();

    expect(app(TicketService::class)->createFromInteraction($comment, admin())->flag)
        ->toBe(TicketFlag::Netral);
});

it('keeps the flag independent of the status when a ticket is closed', function () {
    $service = app(TicketService::class);
    $ticket = newTicket();

    $service->changeFlag($ticket, TicketFlag::Lead, admin());
    $service->close($ticket->refresh(), 'Sudah ditangani.', admin());
    $ticket->refresh();

    // A closed ticket that WAS a lead still counts as one.
    expect($ticket->status)->toBe(TicketStatus::Closed)
        ->and($ticket->flag)->toBe(TicketFlag::Lead);
});

it('filters the ticket list by flag', function () {
    $service = app(TicketService::class);

    $lead = newTicket(['subject' => 'Tiket lead uji']);
    $netral = newTicket(['subject' => 'Tiket netral uji']);
    $service->changeFlag($lead, TicketFlag::Lead, admin());

    $ids = Ticket::filtered(['flag' => 'lead'])->pluck('id');

    expect($ids)->toContain($lead->id)
        ->and($ids)->not->toContain($netral->id);
});

/* -----------------------------------------------------------------
 | Follow-up status (statusfollowupid)
 * ----------------------------------------------------------------- */

it('records the four-state status on the follow-up and maps it to the ticket', function () {
    $service = app(TicketService::class);
    $ticket = newTicket();

    $followUp = $service->addFollowUp($ticket, [
        'action' => 'ditelepon',
        'status' => FollowUpStatus::OnProses->value,
    ], admin());

    expect($followUp->status_after)->toBe(FollowUpStatus::OnProses)
        ->and($ticket->refresh()->status)->toBe(TicketStatus::FollowUp);

    $second = $service->addFollowUp($ticket, [
        'action' => 'dijadwalkan',
        'status' => FollowUpStatus::Assigned->value,
    ], admin());

    expect($second->status_after)->toBe(FollowUpStatus::Assigned)
        ->and($ticket->refresh()->status)->toBe(TicketStatus::Assigned);
});

it('defaults a follow-up to onProses when no status was chosen', function () {
    $ticket = newTicket();

    $followUp = app(TicketService::class)->addFollowUp($ticket, ['action' => 'ditelepon'], admin());

    expect($followUp->status_after)->toBe(FollowUpStatus::OnProses)
        ->and($ticket->refresh()->status)->toBe(TicketStatus::FollowUp);
});

it('maps every follow-up status onto a ticket status and back', function () {
    foreach (FollowUpStatus::cases() as $status) {
        $ticketStatus = $status->toTicketStatus();

        // Round-tripping must land on the status we started from.
        expect(FollowUpStatus::fromTicketStatus($ticketStatus))->toBe($status);
    }

    // And every ticket status has a short-vocabulary equivalent.
    foreach (TicketStatus::cases() as $status) {
        expect(FollowUpStatus::fromTicketStatus($status))->toBeInstanceOf(FollowUpStatus::class);
    }
});

it('does not offer Close in the follow-up form', function () {
    // Closing needs a resolution note, so it goes through the Close form.
    expect(FollowUpStatus::selectable())->not->toHaveKey('close')
        ->and(FollowUpStatus::options())->toHaveKey('close');
});

/* -----------------------------------------------------------------
 | TiketDetail
 * ----------------------------------------------------------------- */

it('records a student on a ticket, keyed by NIM', function () {
    $ticket = newTicket();

    $detail = app(TicketService::class)->saveDetail($ticket, [
        'nim' => 'DTL0000001',
        'nama' => 'Mahasiswa Detail',
        'fakultas' => 'FEB',
        'prodi' => 'Manajemen',
        'provinsi' => 'Kep. Bangka Belitung',
        'kabupaten' => 'Bangka',
        'kecamatan' => 'Sungailiat',
        'kelurahan' => 'Sri Menanti',
    ], admin());

    expect($detail->nim)->toBe('DTL0000001')
        ->and($detail->ticket_id)->toBe($ticket->id)
        ->and($detail->student_id)->toBeNull()   // not in the import
        ->and($detail->academicLabel())->toBe('Manajemen · FEB')
        ->and($detail->regionLabel())->toBe('Sri Menanti, Sungailiat, Bangka, Kep. Bangka Belitung');

    // The ticket's own requester fields are kept in step.
    expect($ticket->refresh()->requester_nim)->toBe('DTL0000001');
});

it('links the detail to the imported student and fills the blanks', function () {
    $student = Student::create([
        'nim' => 'DTL0000002',
        'nama' => 'Mahasiswa Terimport',
        'fakultas' => 'FKIP',
        'program_studi' => 'PGSD',
        'kabupaten' => 'Bangka Tengah',
        'kecamatan' => 'Koba',
        'email' => 'terimport@example.com',
        'kategori_masalah' => 'ongoing_tidak_registrasi',
    ]);

    $ticket = newTicket();

    // Only the NIM is typed; everything else comes from the import.
    $detail = app(TicketService::class)->saveDetail($ticket, ['nim' => 'DTL0000002'], admin());

    expect($detail->student_id)->toBe($student->id)
        ->and($detail->nama)->toBe('Mahasiswa Terimport')
        ->and($detail->fakultas)->toBe('FKIP')
        ->and($detail->prodi)->toBe('PGSD')
        ->and($detail->kabupaten)->toBe('Bangka Tengah')
        ->and($detail->email)->toBe('terimport@example.com');

    // And the ticket is now linked to that student.
    expect($ticket->refresh()->student_id)->toBe($student->id);
});

it('does not let the import overwrite what the operator typed', function () {
    Student::create([
        'nim' => 'DTL0000003',
        'nama' => 'Nama Lama Di Excel',
        'kabupaten' => 'Bangka',
        'kategori_masalah' => 'ongoing_tidak_registrasi',
    ]);

    $ticket = newTicket();

    $detail = app(TicketService::class)->saveDetail($ticket, [
        'nim' => 'DTL0000003',
        'nama' => 'Nama Yang Dikatakan Sendiri',
    ], admin());

    // The operator just spoke to the person; the spreadsheet is months old.
    expect($detail->nama)->toBe('Nama Yang Dikatakan Sendiri')
        // But blanks are still filled from the import.
        ->and($detail->kabupaten)->toBe('Bangka');
});

it('corrects an existing detail instead of adding a duplicate NIM', function () {
    $service = app(TicketService::class);
    $ticket = newTicket();

    $service->saveDetail($ticket, ['nim' => 'DTL0000004', 'prodi' => 'Salah Prodi'], admin());
    $service->saveDetail($ticket, ['nim' => 'DTL0000004', 'prodi' => 'Prodi Benar'], admin());

    $details = $ticket->refresh()->details;

    expect($details)->toHaveCount(1)
        ->and($details->first()->prodi)->toBe('Prodi Benar');
});

it('allows one ticket to cover more than one student', function () {
    $service = app(TicketService::class);
    $ticket = newTicket();

    $service->saveDetail($ticket, ['nim' => 'DTL0000005', 'nama' => 'Mahasiswa A'], admin());
    $service->saveDetail($ticket, ['nim' => 'DTL0000006', 'nama' => 'Mahasiswa B'], admin());

    expect($ticket->refresh()->details->pluck('nim')->all())
        ->toBe(['DTL0000005', 'DTL0000006']);
});

it('finds a ticket by a NIM recorded on its detail', function () {
    $ticket = newTicket(['subject' => 'Tiket yang dicari lewat NIM']);

    app(TicketService::class)->saveDetail($ticket, ['nim' => 'DTL0000007'], admin());

    expect(Ticket::search('DTL0000007')->pluck('id'))->toContain($ticket->id);
});

it('removes a detail from a ticket', function () {
    $service = app(TicketService::class);
    $ticket = newTicket();

    $detail = $service->saveDetail($ticket, ['nim' => 'DTL0000008'], admin());
    $service->removeDetail($ticket, $detail, admin());

    expect($ticket->refresh()->details)->toBeEmpty();
});

it('refuses to add a detail to a closed ticket', function () {
    $service = app(TicketService::class);
    $ticket = newTicket();

    $service->close($ticket, 'Selesai.', admin());

    expect(fn () => $service->saveDetail($ticket->refresh(), ['nim' => 'DTL0000009'], admin()))
        ->toThrow(RuntimeException::class);
});

it('deletes the details when the ticket is force deleted', function () {
    $ticket = newTicket();

    app(TicketService::class)->saveDetail($ticket, ['nim' => 'DTL0000010'], admin());

    $ticket->forceDelete();

    expect(TicketDetail::where('nim', 'DTL0000010')->exists())->toBeFalse();
});

/* -----------------------------------------------------------------
 | pengirimpesanid
 * ----------------------------------------------------------------- */

it('carries the sender id from the comment onto the ticket', function () {
    $comment = instagramComment();
    $comment->forceFill(['author_external_id' => '17841400000000001'])->save();

    $ticket = app(TicketService::class)->createFromInteraction($comment, admin());

    expect($ticket->source_sender_id)->toBe('17841400000000001')
        ->and($ticket->source_username)->toBe('mahasiswa123');
});

/* -----------------------------------------------------------------
 | Screens
 * ----------------------------------------------------------------- */

it('renders the detail panel and flag control on a ticket', function () {
    $ticket = newTicket();

    app(TicketService::class)->saveDetail($ticket, [
        'nim' => 'DTL0000011',
        'nama' => 'Mahasiswa Tampil',
        'prodi' => 'Akuntansi',
        'kabupaten' => 'Belitung',
    ], admin());

    $this->actingAs(admin())->get(route('tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Data Mahasiswa')
        ->assertSee('DTL0000011')
        ->assertSee('Mahasiswa Tampil')
        ->assertSee('Akuntansi')
        ->assertSee('Belitung')
        ->assertSee('Flag')
        ->assertSee('Netral');
});

it('adds a detail through the web form', function () {
    $ticket = newTicket();

    $this->actingAs(admin())->post(route('tickets.details.store', $ticket), [
        'nim' => 'DTL0000012',
        'nama' => 'Lewat Form',
        'fakultas' => 'FST',
    ])->assertRedirect();

    expect($ticket->refresh()->details->first()->nama)->toBe('Lewat Form');
});

it('rejects a detail without a NIM', function () {
    $ticket = newTicket();

    $this->actingAs(admin())
        ->post(route('tickets.details.store', $ticket), ['nama' => 'Tanpa NIM'])
        ->assertSessionHasErrors('nim');

    expect($ticket->refresh()->details)->toBeEmpty();
});

it('will not let one ticket’s detail be deleted through another ticket', function () {
    $service = app(TicketService::class);

    $mine = newTicket();
    $other = newTicket();
    $detail = $service->saveDetail($other, ['nim' => 'DTL0000013'], admin());

    $this->actingAs(admin())
        ->delete(route('tickets.details.destroy', [$mine, $detail]))
        ->assertNotFound();

    expect(TicketDetail::whereKey($detail->id)->exists())->toBeTrue();
});

it('changes the flag through the web form', function () {
    $ticket = newTicket();

    $this->actingAs(admin())
        ->post(route('tickets.flag', $ticket), ['flag' => 'lead'])
        ->assertRedirect();

    expect($ticket->refresh()->flag)->toBe(TicketFlag::Lead);
});

it('shows the lead count on the reports page', function () {
    $ticket = newTicket();
    app(TicketService::class)->changeFlag($ticket, TicketFlag::Lead, admin());

    $this->actingAs(admin())->get(route('reports.tickets'))
        ->assertOk()
        ->assertSee('Tiket Lead')
        ->assertSee('Tiket Netral');
});
