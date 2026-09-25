<?php

/**
 * Renders every new screen with real data in it.
 *
 * RouteMatrixTest already proves no route 500s, but it hits empty pages: a
 * template that breaks only when there is a row to draw would sail past it.
 * These fill the tables first.
 */

use App\Enums\Permission;
use App\Enums\RoleName;
use App\Enums\TaskStatus;
use App\Models\Student;
use App\Models\Task;
use App\Services\Students\StudentAssigner;
use App\Services\Tickets\TicketService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

/** One student, one ticket with a follow-up, and one task. */
function smokeData(): array
{
    $student = Student::create([
        'nim' => 'SMOKE00001',
        'nac' => 'NACSMOKE1',
        'nama' => 'Mahasiswa Smoke',
        'email' => 'smoke@example.com',
        'no_hp_raw' => '081200001111',
        'kabupaten' => 'Bangka',
        'kecamatan' => 'Sungailiat',
        'semester_terakhir' => '2026.1',
        'kategori_masalah' => 'admisi_tidak_bayar',
        'extra' => ['Kolom Asing' => 'nilai asing'],
    ]);

    $service = app(TicketService::class);

    $ticket = $service->createManual([
        'subject' => 'Tiket Smoke Test',
        'description' => 'Deskripsi tiket smoke.',
        'student_id' => $student->id,
    ], admin());

    $service->addFollowUp($ticket, [
        'action' => 'ditelepon',
        'channel_used' => 'telepon',
        'response_text' => 'Catatan follow up smoke.',
        'outcome' => 'positif',
        'additional_data' => ['no_hp' => '081299990000', 'Keterangan' => 'Akan lanjut semester depan'],
    ], admin());

    $task = Task::create([
        'title' => 'Task Smoke Test',
        'description' => 'Deskripsi task smoke.',
        'start_date' => now()->subDays(2),
        'due_date' => now()->addDays(5),
        'status' => TaskStatus::InProgress->value,
        'priority' => 'high',
        'pic_id' => admin()->id,
        'ticket_id' => $ticket->id,
        'created_by' => admin()->id,
        'is_public' => true,
        'progress' => 40,
    ]);

    return compact('student', 'ticket', 'task');
}

it('renders the student list, detail and hand-out screens', function () {
    ['student' => $student] = smokeData();

    // Search for this test's own row rather than assuming it lands on page 1:
    // the live database holds 7.391 imported students, so an unfiltered list
    // shows none of them.
    $this->actingAs(admin())->get(route('students.index', ['q' => 'SMOKE00001']))
        ->assertOk()
        ->assertSee('Mahasiswa Smoke')
        ->assertSee('SMOKE00001')
        // Raw: the label carries an HTML entity, which assertSee would
        // otherwise escape a second time.
        ->assertSee('Unsigned &amp; Ticket', false);

    $this->actingAs(admin())->get(route('students.show', $student))
        ->assertOk()
        ->assertSee('Mahasiswa Smoke')
        // Unmapped source columns are surfaced, not hidden.
        ->assertSee('Kolom Asing')
        ->assertSee('nilai asing');

    $this->actingAs(admin())->get(route('students.unsigned'))
        ->assertOk()
        ->assertSee('Generate Ticket')
        ->assertSee('Assign per Wilayah');

    $this->actingAs(admin())->get(route('students.unsigned', ['q' => 'SMOKE00001']))
        ->assertOk()
        ->assertSee('Mahasiswa Smoke');
});

it('renders the import screens', function () {
    $this->actingAs(admin())->get(route('students.import.index'))
        ->assertOk()
        ->assertSee('Import Data Mahasiswa')
        ->assertSee('Kolom yang Dikenali');
});

it('renders the ticket list, detail and create form', function () {
    ['ticket' => $ticket] = smokeData();

    // Filtered to this test's own ticket: the live database holds real
    // tickets now, and a follow-up moves this one behind every open row, so
    // page 1 is not where it lands.
    $this->actingAs(admin())->get(route('tickets.index', ['q' => $ticket->number]))
        ->assertOk()
        ->assertSee($ticket->number)
        ->assertSee('Tiket Smoke Test');

    $this->actingAs(admin())->get(route('tickets.mine'))->assertOk();

    $this->actingAs(admin())->get(route('tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Tiket Smoke Test')
        ->assertSee('Riwayat Follow Up')
        ->assertSee('Catatan follow up smoke.')
        // Additional data from the follow-up is shown on the touch.
        ->assertSee('Akan lanjut semester depan')
        ->assertSee('Tutup Tiket');

    $this->actingAs(admin())->get(route('tickets.create'))
        ->assertOk()
        ->assertSee('Tiket Baru');

    $this->actingAs(admin())->get(route('tickets.categories.index'))
        ->assertOk()
        ->assertSee('Kategori Tiket');
});

it('prefills the ticket form from a student', function () {
    ['student' => $student] = smokeData();

    $this->actingAs(admin())->get(route('tickets.create', ['student' => $student->id]))
        ->assertOk()
        ->assertSee('SMOKE00001')
        ->assertSee('Mahasiswa Smoke');
});

it('renders the task timeline and detail', function () {
    ['task' => $task] = smokeData();

    $this->actingAs(admin())->get(route('tasks.index'))
        ->assertOk()
        ->assertSee('Task Smoke Test')
        ->assertSee('Papan Rencana');

    $this->actingAs(admin())->get(route('tasks.show', $task))
        ->assertOk()
        ->assertSee('Task Smoke Test')
        ->assertSee('Deskripsi task smoke.');
});

it('offers one report per module from the hub', function () {
    smokeData();

    $this->actingAs(admin())->get(route('reports.index'))
        ->assertOk()
        ->assertSee('Laporan Ticketing')
        ->assertSee('Laporan Data Mahasiswa')
        ->assertSee('Laporan Task Management')
        ->assertSee(route('reports.tickets'), false)
        ->assertSee(route('reports.students'), false)
        ->assertSee(route('reports.tasks'), false);
});

it('renders each module report with real numbers', function () {
    smokeData();

    $this->actingAs(admin())->get(route('reports.tickets'))
        ->assertOk()
        ->assertSee('Open')
        ->assertSee('Pending')
        ->assertSee('Closed');

    $this->actingAs(admin())->get(route('reports.students'))
        ->assertOk()
        ->assertSee('Statistik per Operator')
        ->assertSee('Sebaran Kondisi');

    $this->actingAs(admin())->get(route('reports.tasks'))
        ->assertOk()
        ->assertSee('Beban per PIC')
        ->assertSee('Aktivitas tercatat');
});

it('renders the dashboard with the handling block', function () {
    smokeData();

    $this->actingAs(admin())->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Penanganan')
        ->assertSee('Total mahasiswa');
});

it('shows the new menu entries to an admin', function () {
    $this->actingAs(admin())->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Ticketing')
        ->assertSee('Data Mahasiswa')
        ->assertSee('Task Management')
        ->assertSee('Laporan')
        // Follow Up is deliberately not a menu of its own.
        ->assertDontSee('>Follow Up<', false);
});

it('hides modules an operator may not reach', function () {
    $operator = operatorNamed('smoke-op@test.local');

    expect($operator->hasPermission(Permission::AssignStudents))->toBeFalse()
        ->and($operator->hasPermission(Permission::ViewReports))->toBeFalse();

    $this->actingAs($operator)->get(route('students.unsigned'))->assertForbidden();
    $this->actingAs($operator)->get(route('students.import.index'))->assertForbidden();
    $this->actingAs($operator)->get(route('reports.index'))->assertForbidden();

    // But the screens that are theirs still work.
    $this->actingAs($operator)->get(route('students.index'))->assertOk();
    $this->actingAs($operator)->get(route('tickets.index'))->assertOk();
});

it('lets a director read the new modules without write buttons', function () {
    ['ticket' => $ticket] = smokeData();

    $director = operatorNamed('smoke-director@test.local', RoleName::Director);

    $this->actingAs($director)->get(route('students.index'))->assertOk();
    $this->actingAs($director)->get(route('tickets.index'))->assertOk();
    $this->actingAs($director)->get(route('reports.index'))->assertOk();

    $this->actingAs($director)->get(route('tickets.show', $ticket))
        ->assertOk()
        // Read-only: no closing, no assigning.
        ->assertDontSee('Tutup Tiket')
        ->assertDontSee('Simpan Penugasan');

    // And the write endpoints refuse outright, not just visually.
    $this->actingAs($director)
        ->post(route('tickets.close', $ticket), ['resolution_note' => 'Mencoba menutup'])
        ->assertForbidden();
});

/* -----------------------------------------------------------------
 | Editing — these POST/PUT paths build an audit diff, which used to
 | blow up on enum-cast attributes.
 * ----------------------------------------------------------------- */

it('updates a student and logs what changed', function () {
    ['student' => $student] = smokeData();

    $this->actingAs(admin())->put(route('students.update', $student), [
        'nama' => 'Nama Diperbarui',
        'no_hp_raw' => '081255556666',
        'kategori_masalah' => 'ongoing_tidak_registrasi',
        'assignment_status' => 'assigned',
        'kabupaten' => 'Bangka',
    ])->assertRedirect();

    $student->refresh();

    expect($student->nama)->toBe('Nama Diperbarui')
        ->and($student->no_hp)->toBe('6281255556666')
        ->and($student->kategori_masalah)->toBe(\App\Enums\StudentCondition::OngoingTidakRegistrasi);

    $logged = \App\Models\Activity::where('action', 'student.updated')->latest()->first();

    expect($logged)->not->toBeNull()
        ->and($logged->properties['changes'])->toHaveKey('nama')
        ->and($logged->properties['changes']['nama']['from'])->toBe('Mahasiswa Smoke')
        ->and($logged->properties['changes']['nama']['to'])->toBe('Nama Diperbarui')
        // The enum attribute is recorded by value, not by blowing up.
        ->and($logged->properties['changes']['kategori_masalah']['to'])->toBe('ongoing_tidak_registrasi');
});

it('updates a ticket and logs what changed', function () {
    ['ticket' => $ticket] = smokeData();

    $this->actingAs(admin())->put(route('tickets.update', $ticket), [
        'subject' => 'Judul Diperbarui',
        'priority' => 'urgent',
        'requester_name' => 'Nama Pemohon Baru',
    ])->assertRedirect();

    $ticket->refresh();

    expect($ticket->subject)->toBe('Judul Diperbarui')
        ->and($ticket->priority)->toBe(\App\Enums\Priority::Urgent);

    $logged = $ticket->activities()->where('action', 'ticket.updated')->first();

    expect($logged)->not->toBeNull()
        ->and($logged->properties['changes']['priority']['from'])->toBe('normal')
        ->and($logged->properties['changes']['priority']['to'])->toBe('urgent');
});

it('updates a task and logs what changed', function () {
    ['task' => $task] = smokeData();

    $this->actingAs(admin())->put(route('tasks.update', $task), [
        'title' => 'Task Diperbarui',
        'start_date' => $task->start_date->toDateString(),
        'due_date' => $task->due_date->toDateString(),
        'status' => TaskStatus::Completed->value,
        'priority' => 'low',
    ])->assertRedirect();

    $task->refresh();

    expect($task->title)->toBe('Task Diperbarui')
        ->and($task->status)->toBe(TaskStatus::Completed)
        // Completing stamps the time.
        ->and($task->completed_at)->not->toBeNull();

    $logged = \App\Models\Activity::where('action', 'task.updated')->latest()->first();

    expect($logged->properties['changes']['status']['to'])->toBe('completed')
        // Dates that did not change must not be reported as changes.
        ->and($logged->properties['changes'])->not->toHaveKey('start_date');
});

/* -----------------------------------------------------------------
 | Rows per page
 * ----------------------------------------------------------------- */

it('lets the admin choose how many students to show', function () {
    foreach (range(1, 60) as $i) {
        \App\Models\Student::create([
            'nim' => 'PAGE'.str_pad((string) $i, 6, '0', STR_PAD_LEFT).uniqid(),
            'nama' => "Mahasiswa Halaman {$i}",
            'kategori_masalah' => 'lainnya',
        ]);
    }

    // The selector offers exactly these; anything else is not honoured.
    expect(\App\Http\Controllers\StudentController::PER_PAGE)->toBe([25, 50, 100, 200, 500]);

    $counts = [];

    foreach ([25, 50, 100] as $perPage) {
        $counts[$perPage] = $this->actingAs(admin())
            ->get(route('students.index', ['per_page' => $perPage]))
            ->assertOk()
            ->viewData('students')
            ->count();
    }

    expect($counts[25])->toBe(25)
        ->and($counts[50])->toBe(50)
        ->and($counts[100])->toBeGreaterThan(50);
});

it('ignores a page size nobody offered', function () {
    // ?per_page=999999 over 7.000 rows would render a page no browser can use.
    $this->actingAs(admin())
        ->get(route('students.unsigned', ['per_page' => 999999]))
        ->assertOk()
        ->assertViewHas('perPage', 50);

    $this->actingAs(admin())
        ->get(route('students.unsigned', ['per_page' => 'banyak']))
        ->assertOk()
        ->assertViewHas('perPage', 50);
});

it('keeps the active filters when the page size changes', function () {
    $response = $this->actingAs(admin())->get(route('students.index', [
        'per_page' => 100,
        'kabupaten' => 'Kab. Bangka',
        'kondisi' => 'non_aktif_dn',
    ]));

    $response->assertOk()->assertViewHas('perPage', 100);

    // The control re-submits the filter form, so the other filters must still
    // be rendered as hidden inputs or selected options.
    $response->assertSee('Kab. Bangka')->assertSee('non_aktif_dn', false);
});
