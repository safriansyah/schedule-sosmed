<?php

/**
 * Acceptance tests E–I from the brief: comment → ticket, manual ticket,
 * multiple follow-ups, closing, and task management with its public page.
 */

use App\Enums\Permission;
use App\Enums\RoleName;
use App\Enums\TaskStatus;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Models\AccountMedia;
use App\Models\Interaction;
use App\Models\SocialAccount;
use App\Models\Student;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

/* -----------------------------------------------------------------
 | Test E — comment becomes a ticket
 * ----------------------------------------------------------------- */

it('creates a ticket from an Instagram comment and keeps the source reference', function () {
    $comment = instagramComment();

    $ticket = app(TicketService::class)->createFromInteraction($comment, admin());

    expect($ticket->number)->toStartWith('TKT-')
        ->and($ticket->source)->toBe(TicketSource::Instagram)
        ->and($ticket->interaction_id)->toBe($comment->getKey())
        ->and($ticket->source_username)->toBe('mahasiswa123')
        ->and($ticket->source_text)->toBe('Min saya belum bisa melakukan registrasi')
        ->and($ticket->source_external_id)->toBe($comment->external_id)
        ->and($ticket->source_post_id)->toBe('MEDIA_TEST_1')
        ->and($ticket->source_post_url)->toBe('https://instagram.com/p/TESTSHORTCODE/')
        ->and($ticket->source_created_at?->toDateString())->toBe($comment->occurred_at->toDateString())
        ->and($ticket->status)->toBe(TicketStatus::Open);
});

it('does not create a second ticket for the same comment', function () {
    $comment = instagramComment();
    $service = app(TicketService::class);

    $first = $service->createFromInteraction($comment, admin());
    $second = $service->createFromInteraction($comment, admin());

    expect($second->id)->toBe($first->id)
        ->and(Ticket::where('interaction_id', $comment->getKey())->count())->toBe(1);
});

it('keeps the ticket readable after the source comment is deleted', function () {
    $comment = instagramComment();
    $ticket = app(TicketService::class)->createFromInteraction($comment, admin());

    $comment->delete();
    $ticket->refresh();

    // The snapshot is the point: the comment is gone, the evidence is not.
    expect($ticket->interaction_id)->toBeNull()
        ->and($ticket->source_text)->toBe('Min saya belum bisa melakukan registrasi')
        ->and($ticket->source_username)->toBe('mahasiswa123')
        ->and($ticket->hasSourceReference())->toBeTrue();
});

it('exposes the add-to-ticket route', function () {
    $comment = instagramComment();

    $this->actingAs(admin())
        ->post(route('tickets.fromInteraction', $comment))
        ->assertRedirect();

    expect(Ticket::where('interaction_id', $comment->getKey())->exists())->toBeTrue();
});

/* -----------------------------------------------------------------
 | Test F — manual ticket
 * ----------------------------------------------------------------- */

it('creates a manual ticket with no social origin', function () {
    $ticket = app(TicketService::class)->createManual([
        'subject' => 'Mahasiswa menanyakan jadwal tutorial',
        'description' => 'Ditelepon langsung ke kantor.',
        'source' => TicketSource::Manual->value,
        'priority' => 'normal',
        'requester_name' => 'Budi Santoso',
        'requester_nim' => '010123457',
    ], admin());

    expect($ticket->number)->toStartWith('TKT-')
        ->and($ticket->source)->toBe(TicketSource::Manual)
        ->and($ticket->interaction_id)->toBeNull()
        ->and($ticket->hasSourceReference())->toBeFalse()
        ->and($ticket->requester_name)->toBe('Budi Santoso');
});

it('fills requester details from the student when raised against one', function () {
    $student = Student::create([
        'nim' => 'TKT0000001',
        'nac' => 'NACTKT1',
        'nama' => 'Citra Lestari',
        'email' => 'citra@example.com',
        'no_hp_raw' => '081234567890',
        'kategori_masalah' => 'ongoing_tidak_registrasi',
    ]);

    $ticket = app(TicketService::class)->createManual([
        'subject' => 'Belum registrasi semester 2026.1',
        'source' => TicketSource::StudentImport->value,
        'student_id' => $student->id,
    ], admin());

    expect($ticket->student_id)->toBe($student->id)
        ->and($ticket->requester_name)->toBe('Citra Lestari')
        ->and($ticket->requester_nim)->toBe('TKT0000001')
        ->and($ticket->requester_email)->toBe('citra@example.com');
});

it('gives each ticket a unique sequential number', function () {
    $service = app(TicketService::class);

    $numbers = collect(range(1, 3))
        ->map(fn ($i) => $service->createManual(['subject' => "Tiket {$i}"], admin())->number);

    expect($numbers->unique()->count())->toBe(3)
        ->and($numbers->every(fn ($n) => str_starts_with($n, 'TKT-')))->toBeTrue();
});

/* -----------------------------------------------------------------
 | Test G — many follow-ups on one ticket
 * ----------------------------------------------------------------- */

it('keeps every follow-up instead of replacing the previous one', function () {
    $service = app(TicketService::class);
    $ticket = $service->createManual(['subject' => 'Belum bayar'], admin());

    $service->addFollowUp($ticket, [
        'action' => 'ditelepon',
        'channel_used' => 'telepon',
        'response_text' => 'Sudah menghubungi mahasiswa.',
        'outcome' => 'netral',
    ], admin());

    $service->addFollowUp($ticket, [
        'action' => 'tidak_respon',
        'response_text' => 'Mahasiswa belum memberikan respons.',
    ], admin());

    $service->addFollowUp($ticket, [
        'action' => 'chat_wa',
        'channel_used' => 'wa',
        'response_text' => 'Mahasiswa sudah melakukan pembayaran.',
        'outcome' => 'positif',
    ], admin());

    $ticket->refresh()->load('followUps');

    expect($ticket->followUps)->toHaveCount(3)
        ->and($ticket->follow_up_count)->toBe(3)
        ->and($ticket->status)->toBe(TicketStatus::FollowUp)
        ->and($ticket->followUps->first()->response_text)->toBe('Sudah menghubungi mahasiswa.')
        ->and($ticket->followUps->last()->response_text)->toBe('Mahasiswa sudah melakukan pembayaran.');
});

it('records additional data on the follow-up and applies the known fields', function () {
    $service = app(TicketService::class);
    $ticket = $service->createManual([
        'subject' => 'Perlu dihubungi',
        'requester_phone' => '081200000000',
    ], admin());

    $followUp = $service->addFollowUp($ticket, [
        'action' => 'ditelepon',
        'response_text' => 'Nomor lama tidak aktif.',
        'additional_data' => [
            'no_hp' => '081298765432',
            'email' => 'baru@example.com',
            'Keterangan' => 'Akan melanjutkan semester berikutnya',
        ],
    ], admin());

    $ticket->refresh();

    // Known keys update the ticket…
    expect($ticket->requester_phone)->toBe('081298765432')
        ->and($ticket->requester_email)->toBe('baru@example.com')
        // …and the whole note, including the free-text key, stays on the touch.
        ->and($followUp->additionalPairs())->toHaveKey('Keterangan')
        ->and($followUp->additionalPairs()['no_hp'])->toBe('081298765432');

    // The old value is not lost — it is in the audit trail.
    $logged = $ticket->activities()->where('action', 'ticket.data_updated')->first();

    expect($logged)->not->toBeNull()
        ->and($logged->properties['changes']['requester_phone']['from'])->toBe('081200000000');
});

/* -----------------------------------------------------------------
 | Test H — closing
 * ----------------------------------------------------------------- */

it('stores who closed a ticket, when, and the resolution', function () {
    $service = app(TicketService::class);
    $ticket = $service->createManual(['subject' => 'Belum registrasi'], admin());

    $service->close($ticket, 'Mahasiswa sudah melakukan registrasi dan pembayaran.', admin());
    $ticket->refresh();

    expect($ticket->status)->toBe(TicketStatus::Closed)
        ->and($ticket->closed_by)->toBe(admin()->id)
        ->and($ticket->closed_at)->not->toBeNull()
        ->and($ticket->resolution_note)->toBe('Mahasiswa sudah melakukan registrasi dan pembayaran.')
        ->and($ticket->isEditable())->toBeFalse();
});

it('keeps a closed ticket and its history readable', function () {
    $service = app(TicketService::class);
    $ticket = $service->createManual(['subject' => 'Belum bayar'], admin());

    $service->addFollowUp($ticket, ['action' => 'ditelepon', 'response_text' => 'Dihubungi.'], admin());
    $service->close($ticket, 'Sudah dibayar.', admin());

    $this->actingAs(admin())
        ->get(route('tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Sudah dibayar.')
        ->assertSee('Dihubungi.');

    expect($ticket->refresh()->followUps)->toHaveCount(1)
        ->and(Ticket::find($ticket->id))->not->toBeNull();
});

it('refuses to add a follow-up to a closed ticket', function () {
    $service = app(TicketService::class);
    $ticket = $service->createManual(['subject' => 'Sudah selesai'], admin());

    $service->close($ticket, 'Selesai.', admin());

    expect(fn () => $service->addFollowUp($ticket->refresh(), ['action' => 'ditelepon'], admin()))
        ->toThrow(RuntimeException::class);
});

it('reopens a closed ticket while keeping the resolution on record', function () {
    $service = app(TicketService::class);
    $ticket = $service->createManual(['subject' => 'Belum bayar'], admin());

    $service->close($ticket, 'Sudah dibayar.', admin());
    $service->reopen($ticket->refresh(), admin());
    $ticket->refresh();

    expect($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->closed_at)->toBeNull()
        ->and($ticket->resolution_note)->toBe('Sudah dibayar.');
});

/* -----------------------------------------------------------------
 | Assignment & scoping
 * ----------------------------------------------------------------- */

it('records the hand-over history when a ticket changes hands', function () {
    $service = app(TicketService::class);
    $ticket = $service->createManual(['subject' => 'Perlu follow up'], admin());

    $operator = operatorNamed('ticket-op@test.local');

    $service->assign($ticket, $operator, admin());
    $ticket->refresh();

    expect($ticket->assigned_to)->toBe($operator->id)
        ->and($ticket->status)->toBe(TicketStatus::Assigned)
        ->and($ticket->assignments()->count())->toBe(1)
        ->and($ticket->assignments()->first()->to_user_id)->toBe($operator->id);
});

it('shows an operator only their own tickets', function () {
    $service = app(TicketService::class);
    $operatorA = operatorNamed('ticket-a@test.local');
    $operatorB = operatorNamed('ticket-b@test.local');

    $mine = $service->createManual(['subject' => 'Punya A'], admin());
    $theirs = $service->createManual(['subject' => 'Punya B'], admin());

    $service->assign($mine, $operatorA, admin());
    $service->assign($theirs, $operatorB, admin());

    expect(Ticket::visibleTo($operatorA)->pluck('id'))->toContain($mine->id)
        ->and(Ticket::visibleTo($operatorA)->pluck('id'))->not->toContain($theirs->id);

    $this->actingAs($operatorA)->get(route('tickets.show', $theirs))->assertForbidden();
    $this->actingAs($operatorA)->get(route('tickets.show', $mine))->assertOk();
});

/* -----------------------------------------------------------------
 | Test I — task management
 * ----------------------------------------------------------------- */

it('creates a task and shows it on the timeline', function () {
    $operator = operatorNamed('task-pic@test.local');

    $this->actingAs(admin())->post(route('tasks.store'), [
        'title' => 'Uji Timeline Task 2026.1',
        'start_date' => '2026-09-20',
        'due_date' => '2026-09-30',
        'status' => TaskStatus::Planned->value,
        'priority' => 'normal',
        'pic_id' => $operator->id,
        'is_public' => '1',
    ])->assertRedirect();

    $task = Task::where('title', 'Uji Timeline Task 2026.1')->first();

    expect($task)->not->toBeNull()
        ->and($task->is_public)->toBeTrue()
        ->and($task->pic_id)->toBe($operator->id)
        ->and($task->durationDays())->toBe(11);

    $this->actingAs(admin())
        ->get(route('tasks.index', ['from' => '2026-09-18', 'to' => '2026-10-02']))
        ->assertOk()
        ->assertSee('Uji Timeline Task 2026.1');
});

it('publishes a public task without exposing anything internal', function () {
    $operator = operatorNamed('task-secret@test.local');

    $task = Task::create([
        'title' => 'Sosialisasi Registrasi Mahasiswa',
        'description' => 'Kegiatan sosialisasi di kampus.',
        'start_date' => now()->subDay(),
        'due_date' => now()->addDays(3),
        'status' => TaskStatus::InProgress->value,
        'priority' => 'high',
        'pic_id' => $operator->id,
        'created_by' => admin()->id,
        'is_public' => true,
    ]);

    // Jadwal kegiatan now needs a login, but stays whitelisted: even a
    // signed-in viewer sees only the public fields.
    $response = $this->actingAs(User::withRole(RoleName::Operator)->firstOrFail())->get(route('public.tasks'));

    $response->assertOk()
        ->assertSee('Sosialisasi Registrasi Mahasiswa')
        ->assertSee('Kegiatan sosialisasi di kampus.')
        // The PIC is internal and must not appear.
        ->assertDontSee($operator->name)
        ->assertDontSee($operator->email);

    expect($task->publicPayload())
        ->toHaveKeys(['title', 'description', 'start_date', 'due_date', 'status', 'progress'])
        ->and(array_keys($task->publicPayload()))
        ->not->toContain('pic_id', 'created_by', 'ticket_id');
});

it('never leaks student data on the public task page', function () {
    $student = Student::create([
        'nim' => 'SECRET0001',
        'nac' => 'NACSECRET1',
        'nama' => 'Rahasia Mahasiswa',
        'no_hp_raw' => '081299998888',
        'email' => 'rahasia@example.com',
        'kategori_masalah' => 'ongoing_tidak_registrasi',
    ]);

    $ticket = app(TicketService::class)->createManual([
        'subject' => 'Kasus rahasia mahasiswa',
        'student_id' => $student->id,
    ], admin());

    // A task that references the ticket — the tightest possible link between
    // a public task and personal data.
    Task::create([
        'title' => 'Kegiatan Publik',
        'start_date' => now(),
        'due_date' => now()->addDay(),
        'status' => TaskStatus::Planned->value,
        'priority' => 'normal',
        'ticket_id' => $ticket->id,
        'is_public' => true,
    ]);

    $response = $this->actingAs(User::withRole(RoleName::Operator)->firstOrFail())->get(route('public.tasks'));

    $response->assertOk()
        ->assertSee('Kegiatan Publik')
        ->assertDontSee('SECRET0001')
        ->assertDontSee('NACSECRET1')
        ->assertDontSee('Rahasia Mahasiswa')
        ->assertDontSee('081299998888')
        ->assertDontSee('rahasia@example.com')
        ->assertDontSee($ticket->number);
});

it('hides private tasks from the public page', function () {
    Task::create([
        'title' => 'Rapat Internal Tim',
        'start_date' => now(),
        'due_date' => now()->addDay(),
        'status' => TaskStatus::Planned->value,
        'priority' => 'normal',
        'is_public' => false,
    ]);

    $this->actingAs(User::withRole(RoleName::Operator)->firstOrFail())->get(route('public.tasks'))
        ->assertOk()
        ->assertDontSee('Rapat Internal Tim');
});

it('stops a user without the publish permission from making a task public', function () {
    $operator = operatorNamed('task-nopublish@test.local');

    // The Operator role has neither ManageTasks nor PublishTasks.
    expect($operator->hasPermission(Permission::PublishTasks))->toBeFalse();

    $this->actingAs($operator)->post(route('tasks.store'), [
        'title' => 'Coba Publik',
        'start_date' => now()->toDateString(),
        'due_date' => now()->addDay()->toDateString(),
        'status' => TaskStatus::Planned->value,
        'priority' => 'normal',
        'is_public' => '1',
    ])->assertForbidden();

    expect(Task::where('title', 'Coba Publik')->exists())->toBeFalse();
});

/* -----------------------------------------------------------------
 | Attachments
 * ----------------------------------------------------------------- */

it('stores an attachment on a ticket and on a follow-up', function () {
    Illuminate\Support\Facades\Storage::fake('public');

    $this->actingAs(admin())->post(route('tickets.store'), [
        'subject' => 'Tiket dengan lampiran',
        'source' => TicketSource::Manual->value,
        'priority' => 'normal',
        'attachment' => Illuminate\Http\UploadedFile::fake()->create('bukti-bayar.pdf', 64),
    ])->assertRedirect();

    $ticket = Ticket::where('subject', 'Tiket dengan lampiran')->first();

    expect($ticket->hasAttachment())->toBeTrue()
        ->and($ticket->attachment_name)->toBe('bukti-bayar.pdf');

    Illuminate\Support\Facades\Storage::disk('public')->assertExists($ticket->attachment_path);

    $this->actingAs(admin())->post(route('tickets.followUp', $ticket), [
        'action' => 'chat_wa',
        'response_text' => 'Mahasiswa mengirim bukti transfer.',
        'attachment' => Illuminate\Http\UploadedFile::fake()->image('transfer.jpg'),
    ])->assertRedirect();

    $followUp = $ticket->refresh()->followUps()->first();

    expect($followUp->hasAttachment())->toBeTrue()
        ->and($followUp->attachment_name)->toBe('transfer.jpg');

    Illuminate\Support\Facades\Storage::disk('public')->assertExists($followUp->attachment_path);
});

it('keeps a task attachment when the form is saved without a new file', function () {
    Illuminate\Support\Facades\Storage::fake('public');

    $this->actingAs(admin())->post(route('tasks.store'), [
        'title' => 'Task dengan lampiran',
        'start_date' => now()->toDateString(),
        'due_date' => now()->addDay()->toDateString(),
        'status' => TaskStatus::Planned->value,
        'priority' => 'normal',
        'attachment' => Illuminate\Http\UploadedFile::fake()->create('rencana.pdf', 32),
    ])->assertRedirect();

    $task = Task::where('title', 'Task dengan lampiran')->first();
    $original = $task->attachment_path;

    expect($original)->not->toBeNull()
        ->and($task->attachment_name)->toBe('rencana.pdf');

    // Saving again with no file must not clear what is already there.
    $this->actingAs(admin())->put(route('tasks.update', $task), [
        'title' => 'Task dengan lampiran',
        'start_date' => $task->start_date->toDateString(),
        'due_date' => $task->due_date->toDateString(),
        'status' => TaskStatus::InProgress->value,
        'priority' => 'normal',
    ])->assertRedirect();

    $task->refresh();

    expect($task->attachment_path)->toBe($original)
        ->and($task->attachment_name)->toBe('rencana.pdf')
        ->and($task->status)->toBe(TaskStatus::InProgress);
});
