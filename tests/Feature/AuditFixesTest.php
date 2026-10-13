<?php

/**
 * Fixes from the 2026-10-13 audit: permission gaps on the bulk student
 * actions, ticket ↔ student state, validation that used to 500, the lead
 * import's edge cases and the guest book's "back to the queue".
 */

use App\Enums\AssignmentStatus;
use App\Enums\GuestBookStatus;
use App\Enums\LeadSource;
use App\Enums\RoleName;
use App\Enums\StudentCondition;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Models\GuestBookEntry;
use App\Models\LeadImport;
use App\Models\Student;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

function auditStudent(array $overrides = []): Student
{
    return Student::create($overrides + [
        'nim' => 'AUD'.substr(uniqid(), -7),
        'nama' => 'Mahasiswa Audit',
        'kabupaten' => 'Kabupaten Audit',
        'kategori_masalah' => StudentCondition::OngoingBillingPending->value,
        'assignment_status' => AssignmentStatus::Unassigned->value,
    ]);
}

function auditUser(RoleName $role): User
{
    return User::withRole($role)->firstOrFail();
}

/* -----------------------------------------------------------------
 | Bulk student actions belong to the Unsigned & Ticket screen's role
 * ----------------------------------------------------------------- */

it('keeps the bulk ticket actions to whoever may hand out students', function () {
    $student = auditStudent();
    $a = auditUser(RoleName::Operator);
    $b = auditUser(RoleName::FollowUp);

    // The front desk can create and assign single tickets, but not move or
    // raise a whole region's.
    $frontdesk = auditUser(RoleName::Frontdesk);
    $this->actingAs($frontdesk)->post(route('students.assign.move'), ['from_operator_id' => $a->id, 'to_operator_id' => $b->id, 'kabupaten' => 'Kabupaten Audit'])->assertForbidden();
    $this->actingAs($frontdesk)->post(route('students.assign.region'), ['operator_id' => $b->id, 'kabupaten' => 'Kabupaten Audit'])->assertForbidden();
    $this->actingAs($frontdesk)->post(route('students.tickets.selected'), ['ids' => [$student->id], 'operator_id' => $b->id])->assertForbidden();

    // An operator may create tickets, but not generate a region's worth.
    $this->actingAs($a)->post(route('students.tickets.generate'), ['kabupaten' => 'Kabupaten Audit', 'operator' => $a->id])->assertForbidden();

    expect(Ticket::where('requester_nim', $student->nim)->exists())->toBeFalse();
});

it('acts on exactly the students its count shows, filters included', function () {
    $operator = auditUser(RoleName::Operator);
    auditStudent(['nama' => 'Audit Cocok', 'nim' => 'AUDMATCH01']);
    auditStudent(['nama' => 'Audit Lain', 'nim' => 'AUDOTHER01']);

    // The list is filtered by search, inside the region; the button carries both.
    $this->actingAs(auditUser(RoleName::Manager))
        ->get(route('students.unsigned', ['kabupaten' => 'Kabupaten Audit', 'q' => 'AUDMATCH']))
        ->assertOk()
        ->assertSee('name="q" value="AUDMATCH"', false);

    $this->actingAs(auditUser(RoleName::Manager))
        ->post(route('students.assign.region'), ['kabupaten' => 'Kabupaten Audit', 'q' => 'AUDMATCH', 'operator_id' => $operator->id])
        ->assertSessionHas('success');

    expect(Ticket::where('requester_nim', 'AUDMATCH01')->exists())->toBeTrue()
        ->and(Ticket::where('requester_nim', 'AUDOTHER01')->exists())->toBeFalse();
});

/* -----------------------------------------------------------------
 | Tickets and the students behind them
 * ----------------------------------------------------------------- */

it('does not let a ticket pull in a student the creator cannot see', function () {
    $hidden = auditStudent(['nama' => 'Mahasiswa Rahasia Audit', 'no_hp' => '6281299990001']);
    $operator = auditUser(RoleName::Operator);

    $this->actingAs($operator)->get(route('tickets.create', ['student' => $hidden->id]))
        ->assertOk()->assertDontSee('Mahasiswa Rahasia Audit');

    $this->actingAs($operator)->post(route('tickets.store'), [
        'subject' => 'Coba tarik data',
        'source' => array_key_first(TicketSource::manualOptions()),
        'priority' => 'normal',
        'student_id' => $hidden->id,
    ])->assertSessionHasErrors('student_id');

    // The Manager sees every student, so for them it works.
    $this->actingAs(auditUser(RoleName::Manager))->get(route('tickets.create', ['student' => $hidden->id]))
        ->assertOk()->assertSee('Mahasiswa Rahasia Audit');
});

it('answers a made-up priority with a validation error, not a server error', function () {
    $this->actingAs(auditUser(RoleName::Manager))->post(route('tickets.store'), [
        'subject' => 'Prioritas aneh',
        'source' => array_key_first(TicketSource::manualOptions()),
        'priority' => 'zzz',
        'flag' => 'zzz',
    ])->assertSessionHasErrors(['priority', 'flag']);
});

it('opens the student again when their ticket is reopened', function () {
    $operator = auditUser(RoleName::Operator);
    $student = auditStudent(['assigned_to' => $operator->id, 'assignment_status' => AssignmentStatus::Assigned->value]);
    $ticket = Ticket::create([
        'number' => 'TKT-AUD-'.substr(uniqid(), -6), 'subject' => 'Audit', 'source' => TicketSource::Manual,
        'status' => TicketStatus::Assigned, 'assigned_to' => $operator->id, 'student_id' => $student->id,
        'created_by' => auditUser(RoleName::Manager)->id,
    ]);
    $manager = auditUser(RoleName::Manager);

    $this->actingAs($manager)->post(route('tickets.close', $ticket), ['resolution_note' => 'Selesai.']);
    expect($student->fresh()->assignment_status)->toBe(AssignmentStatus::Closed);

    $this->actingAs($manager)->post(route('tickets.reopen', $ticket));
    expect($student->fresh()->assignment_status)->toBe(AssignmentStatus::Assigned);
});

it('lists on a student page only the tickets the viewer can open', function () {
    $mine = auditUser(RoleName::Operator);
    $other = auditUser(RoleName::FollowUp);
    $student = auditStudent(['assigned_to' => $mine->id, 'assignment_status' => AssignmentStatus::Assigned->value]);
    $manager = auditUser(RoleName::Manager);

    foreach ([[$mine, 'Tiket Audit Milik Saya'], [$other, 'Tiket Audit Milik Orang Lain']] as [$who, $subject]) {
        Ticket::create(['number' => 'TKT-AUD-'.substr(uniqid(), -6), 'subject' => $subject, 'source' => TicketSource::Manual,
            'status' => TicketStatus::Assigned, 'assigned_to' => $who->id, 'student_id' => $student->id, 'created_by' => $manager->id]);
    }

    $this->actingAs($mine)->get(route('students.show', $student))
        ->assertOk()->assertSee('Tiket Audit Milik Saya')->assertDontSee('Tiket Audit Milik Orang Lain');
});

/* -----------------------------------------------------------------
 | Data Lead import edge cases
 * ----------------------------------------------------------------- */

it('turns away a file that only pretends to be xlsx, without a 500', function () {
    Storage::fake('local');
    $zip = tempnam(sys_get_temp_dir(), 'notxlsx');
    $archive = new ZipArchive;
    $archive->open($zip, ZipArchive::OVERWRITE);
    $archive->addFromString('word/document.xml', '<w:document/>');
    $archive->close();

    $before = LeadImport::count();

    $this->actingAs(auditUser(RoleName::Manager))
        ->post(route('leads.import.store'), ['source' => LeadSource::Mitra->value, 'file' => new UploadedFile($zip, 'laporan.xlsx', null, null, true)])
        ->assertSessionHasErrors('file');

    expect(LeadImport::count())->toBe($before);
    @unlink($zip);
});

it('finds the heading row under a title and reports real row numbers', function () {
    Storage::fake('local');
    $manager = auditUser(RoleName::Manager);
    $csv = "DATA LEAD OKTOBER\n\nNama,No HP,Kota\nLead Audit Satu,081299990011,Bangka\n\nLead Audit Rusak,12345,Bangka\n";

    $this->actingAs($manager)->post(route('leads.import.store'), [
        'source' => LeadSource::AbsensiSosprom->value,
        'file' => UploadedFile::fake()->createWithContent('sosprom.csv', $csv),
    ]);
    $import = LeadImport::latest('id')->firstOrFail();

    $this->actingAs($manager)->get(route('leads.import.preview', $import))
        ->assertOk()->assertDontSee('Kolom wajib tidak ditemukan')->assertSee('Lead Audit Satu');

    $this->actingAs($manager)->post(route('leads.import.confirm', $import));
    $import->refresh();

    expect($import->imported_count)->toBe(1)
        ->and($import->failed_count)->toBe(1)
        // Line 6 in the file, as Excel or a text editor shows it.
        ->and($import->errors[0]['line'])->toBe(6);
});

/* -----------------------------------------------------------------
 | Buku Tamu
 * ----------------------------------------------------------------- */

it('forgets the old Selesai details when a visit goes back to the queue', function () {
    $entry = GuestBookEntry::forceCreate([
        'queue_date' => now('Asia/Jakarta')->toDateString(), 'queue_number' => 900 + random_int(0, 99),
        'whatsapp' => '6281299990021', 'phone' => '6281299990021', 'name' => 'Tamu Audit', 'gender' => 'laki_laki',
        'service' => \App\Enums\GuestBookService::cases()[0]->value, 'status' => GuestBookStatus::Done->value,
        'service_process' => 'langsung', 'resolution' => 'langsung', 'completed_by' => auditUser(RoleName::Frontdesk)->id,
        'completion_note' => 'Catatan lama', 'finished_at' => now(),
    ]);

    $this->actingAs(auditUser(RoleName::Frontdesk))
        ->postJson(route('guest-book.admin.status', $entry), ['status' => GuestBookStatus::Waiting->value])
        ->assertOk();

    expect($entry->fresh())
        ->resolution->toBeNull()
        ->service_process->toBeNull()
        ->completed_by->toBeNull()
        ->completion_note->toBeNull()
        ->finished_at->toBeNull();
});
