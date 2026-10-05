<?php

/**
 * The features added on 2026-09-21: Generate Ticket, region hand-out, a
 * configurable ticket ID, region filters on the ticket list, and the task
 * planner's checkmarks.
 *
 * These run against the development database, which holds 7.391 real
 * students, so every query here is narrowed to rows this file created.
 */

use App\Enums\AssignmentStatus;
use App\Enums\RoleName;
use App\Enums\StudentCondition;
use App\Enums\TicketSource;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Students\StudentTicketGenerator;
use App\Services\Tickets\TicketNumberFormatter;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

/** Students this file owns, tagged by a NIM prefix nothing else uses. */
function genStudents(int $count = 5, array $overrides = []): void
{
    for ($i = 0; $i < $count; $i++) {
        Student::create(array_merge([
            'nim' => 'GEN'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
            'nama' => "Mahasiswa Generate {$i}",
            'kabupaten' => 'Kabupaten Generate',
            'kecamatan' => $i % 2 === 0 ? 'Kecamatan Ganjil' : 'Kecamatan Genap',
            'kategori_masalah' => StudentCondition::OngoingBillingPending->value,
            'assignment_status' => AssignmentStatus::Unassigned->value,
        ], $overrides));
    }
}

function genFilter(): array
{
    return ['q' => 'GEN000'];
}

/* -----------------------------------------------------------------
 | Generate Ticket
 * ----------------------------------------------------------------- */

it('raises one ticket per student with the import source', function () {
    genStudents(5);

    $result = app(StudentTicketGenerator::class)->generate(genFilter(), admin());

    expect($result['created'])->toBe(5);

    $tickets = Ticket::whereIn('requester_nim', Student::where('nim', 'like', 'GEN%')->pluck('nim'))->get();

    expect($tickets)->toHaveCount(5)
        ->and($tickets->pluck('source')->unique()->all())->toBe([TicketSource::StudentImport])
        // The student is linked, not merely named — the ticket page, the
        // student page and the region filter all read that link.
        ->and($tickets->whereNull('student_id'))->toHaveCount(0);
});

it('records the NIM as a ticket detail so the student panel fills in', function () {
    genStudents(2);

    app(StudentTicketGenerator::class)->generate(genFilter(), admin());

    $ticket = Ticket::where('requester_nim', 'GEN0000000')->with('details')->firstOrFail();

    expect($ticket->details)->toHaveCount(1)
        ->and($ticket->details->first()->nim)->toBe('GEN0000000')
        ->and($ticket->details->first()->student_id)->toBe($ticket->student_id);
});

it('is safe to press twice', function () {
    genStudents(4);

    $generator = app(StudentTicketGenerator::class);

    $first = $generator->generate(genFilter(), admin());
    $second = $generator->generate(genFilter(), admin());

    // The second run creates nothing and says why, rather than doubling every
    // operator's queue.
    expect($first['created'])->toBe(4)
        ->and($second['created'])->toBe(0)
        ->and($second['skipped'])->toBe(4)
        ->and(Ticket::where('requester_nim', 'like', 'GEN%')->count())->toBe(4);
});

it('hands the ticket to whoever already holds the student', function () {
    $operator = operatorNamed('gen-op@test.local');

    genStudents(3, [
        'assigned_to' => $operator->id,
        'assignment_status' => AssignmentStatus::Assigned->value,
    ]);

    app(StudentTicketGenerator::class)->generate(genFilter(), admin());

    $tickets = Ticket::where('requester_nim', 'like', 'GEN%')->get();

    expect($tickets->pluck('assigned_to')->unique()->all())->toBe([$operator->id]);
});

it('no longer generates tickets for everyone without an operator and a region', function () {
    genStudents(3);

    $this->actingAs(admin())
        ->from(route('students.unsigned'))
        ->post(route('students.tickets.generate'), genFilter())
        ->assertRedirect()
        ->assertSessionHasErrors('generate');

    expect(Ticket::where('requester_nim', 'like', 'GEN%')->count())->toBe(0);
});

it('assigns a region and raises its tickets in one step', function () {
    genStudents(4);
    $operator = operatorNamed('gen-region@test.local');

    $this->actingAs(admin())
        ->from(route('students.unsigned'))
        ->post(route('students.assign.region'), ['kabupaten' => 'Kabupaten Generate', 'operator_id' => $operator->id])
        ->assertRedirect()
        ->assertSessionHas('success');

    $students = Student::where('nim', 'like', 'GEN%')->get();
    $tickets = Ticket::where('requester_nim', 'like', 'GEN%')->get();

    expect($students->pluck('assigned_to')->unique()->all())->toBe([$operator->id])
        ->and($tickets)->toHaveCount(4)
        ->and($tickets->pluck('assigned_to')->unique()->all())->toBe([$operator->id])
        ->and($tickets->pluck('source')->unique()->map->value->all())->toBe([TicketSource::StudentImport->value]);

    // Without a region nothing happens at all.
    $this->actingAs(admin())
        ->post(route('students.assign.region'), ['operator_id' => $operator->id])
        ->assertSessionHasErrors('kabupaten');
});

it('moves a region from the wrong operator to the right one, tickets included', function () {
    genStudents(4);
    $wrong = operatorNamed('gen-wrong@test.local');
    $right = operatorNamed('gen-right@test.local');

    $this->actingAs(admin())->post(route('students.assign.region'), ['kabupaten' => 'Kabupaten Generate', 'operator_id' => $wrong->id]);

    // Only one kecamatan moves; the other stays with the first operator.
    $this->actingAs(admin())
        ->from(route('students.unsigned'))
        ->post(route('students.assign.move'), [
            'kabupaten' => 'Kabupaten Generate',
            'kecamatan' => 'Kecamatan Ganjil',
            'from_operator_id' => $wrong->id,
            'to_operator_id' => $right->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $moved = Student::where('nim', 'like', 'GEN%')->where('kecamatan', 'Kecamatan Ganjil')->get();
    $stayed = Student::where('nim', 'like', 'GEN%')->where('kecamatan', 'Kecamatan Genap')->get();

    expect($moved->pluck('assigned_to')->unique()->all())->toBe([$right->id])
        ->and($stayed->pluck('assigned_to')->unique()->all())->toBe([$wrong->id])
        ->and(Ticket::whereIn('student_id', $moved->pluck('id'))->pluck('assigned_to')->unique()->all())->toBe([$right->id])
        ->and(Ticket::whereIn('student_id', $stayed->pluck('id'))->pluck('assigned_to')->unique()->all())->toBe([$wrong->id])
        ->and(\App\Models\TicketAssignment::whereIn('ticket_id', Ticket::whereIn('student_id', $moved->pluck('id'))->pluck('id'))
            ->where('to_user_id', $right->id)->where('note', 'like', 'Pindah operator%')->count())->toBe($moved->count());

    // Same operator on both sides, or no region: refused.
    $this->actingAs(admin())->post(route('students.assign.move'), [
        'kabupaten' => 'Kabupaten Generate', 'from_operator_id' => $right->id, 'to_operator_id' => $right->id,
    ])->assertSessionHasErrors('to_operator_id');

    $this->actingAs(admin())->post(route('students.assign.move'), [
        'from_operator_id' => $wrong->id, 'to_operator_id' => $right->id,
    ])->assertSessionHasErrors('move');
});

/* -----------------------------------------------------------------
 | Ticket numbering
 * ----------------------------------------------------------------- */

it('renders every supported token', function () {
    $formatter = app(TicketNumberFormatter::class);
    $at = \Illuminate\Support\Carbon::parse('2026-02-07');

    expect($formatter->preview('TKT-{YYYY}{MM}-{SEQ:4}', 11, $at))->toBe('TKT-202602-0011')
        ->and($formatter->preview('UT{YY}{MM}{DD}/{SEQ:3}', 9, $at))->toBe('UT260207/009')
        ->and($formatter->preview('T-{SEQ}', 42, $at))->toBe('T-42');
});

it('rejects a pattern that would break ticket creation', function () {
    $formatter = app(TicketNumberFormatter::class);

    // No counter: every ticket in the period would get the same number and the
    // unique index would reject the second one.
    expect(fn () => $formatter->validatePattern('TKT-2026'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $formatter->validatePattern('TKT-{BULAN}-{SEQ:3}'))
        ->toThrow(InvalidArgumentException::class);

    // Longer than tickets.number can hold, once the counter reaches 5 digits.
    expect(fn () => $formatter->validatePattern('TIKET-PANJANG-SEKALI-{YYYY}{MM}-{SEQ:6}'))
        ->toThrow(InvalidArgumentException::class);
});

it('counts on from the highest number, not from the row count', function () {
    // A gap is the case that matters: force-deleting a ticket leaves the
    // numbers non-contiguous, and a count-based scheme would then hand out one
    // that already exists and retry for ever.
    $formatter = app(TicketNumberFormatter::class);
    $formatter->saveFormats([
        ['code' => 'GAPTEST', 'label' => 'Uji celah', 'pattern' => 'GAPTEST-{SEQ:4}', 'reset' => 'never', 'default' => true],
    ]);

    foreach (['GAPTEST-0001', 'GAPTEST-0002', 'GAPTEST-0003'] as $number) {
        Ticket::create(['number' => $number, 'source' => 'manual', 'subject' => 'Uji nomor']);
    }

    Ticket::where('number', 'GAPTEST-0002')->forceDelete();

    expect($formatter->next('GAPTEST'))->toBe('GAPTEST-0004');
});

it('saves several formats and numbers each from its own counter', function () {
    $this->actingAs(admin())
        ->put(route('tickets.settings.update'), [
            'formats' => [
                ['code' => 'UJA', 'label' => 'Uji Umum', 'pattern' => 'UJA-{YYYY}-{SEQ:4}', 'reset' => 'yearly'],
                ['code' => 'UJU', 'label' => 'Uji Keuangan', 'pattern' => 'UJU-{YYYY}-{SEQ:4}', 'reset' => 'yearly'],
            ],
            'default' => 0,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $formatter = app(TicketNumberFormatter::class);

    expect($formatter->defaultFormat()['code'])->toBe('UJA')
        ->and(collect($formatter->formats())->pluck('code')->all())->toBe(['UJA', 'UJU']);

    // No code given: the default format applies.
    $first = Ticket::createWithNumber(['source' => 'manual', 'subject' => 'Tiket default']);

    // A named one draws from its OWN counter, so both start at 1.
    $second = Ticket::createWithNumber(['source' => 'manual', 'subject' => 'Tiket keuangan'], 'UJU');

    $year = now()->format('Y');

    expect($first->number)->toBe("UJA-{$year}-0001")
        ->and($second->number)->toBe("UJU-{$year}-0001");
});

it('refuses a bad format from the screen with a readable reason', function () {
    $this->actingAs(admin())
        ->from(route('tickets.settings.edit'))
        ->put(route('tickets.settings.update'), [
            'formats' => [['code' => 'UJZ', 'label' => '', 'pattern' => 'TANPA-NOMOR', 'reset' => 'never']],
            'default' => 0,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('formats');
});

it('refuses two formats sharing a code', function () {
    // They would share a counter and hand out the same number twice.
    $this->actingAs(admin())
        ->from(route('tickets.settings.edit'))
        ->put(route('tickets.settings.update'), [
            'formats' => [
                ['code' => 'UJD', 'label' => '', 'pattern' => 'UJD-{SEQ:4}', 'reset' => 'never'],
                ['code' => 'UJD', 'label' => '', 'pattern' => 'X-{SEQ:4}', 'reset' => 'never'],
            ],
            'default' => 0,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('formats');
});

it('keeps the settings screen out of reach without the permission', function () {
    $this->actingAs(operatorNamed('gen-plain@test.local'))
        ->get(route('tickets.settings.edit'))
        ->assertForbidden();
});

/* -----------------------------------------------------------------
 | Region filters on the ticket list
 * ----------------------------------------------------------------- */

it('shows region filters only for tickets from the student import', function () {
    $this->actingAs(admin())
        ->get(route('tickets.index'))
        ->assertOk()
        ->assertDontSee('Wilayah mahasiswa');

    $this->actingAs(admin())
        ->get(route('tickets.index', ['source' => TicketSource::StudentImport->value]))
        ->assertOk()
        ->assertSee('Wilayah mahasiswa');
});

it('narrows the ticket list by the student region', function () {
    genStudents(4);
    app(StudentTicketGenerator::class)->generate(genFilter(), admin());

    $response = $this->actingAs(admin())->get(route('tickets.index', [
        'source' => TicketSource::StudentImport->value,
        'kabupaten' => 'Kabupaten Generate',
        'kecamatan' => 'Kecamatan Ganjil',
        'q' => 'GEN000',
    ]));

    $response->assertOk();

    // Two of the four students live in that kecamatan.
    $shown = Ticket::query()
        ->where('source', TicketSource::StudentImport->value)
        ->regionOf(['kabupaten' => 'Kabupaten Generate', 'kecamatan' => 'Kecamatan Ganjil'])
        ->count();

    expect($shown)->toBe(2);
});

/* -----------------------------------------------------------------
 | Task planner
 * ----------------------------------------------------------------- */

function plannerTask(): Task
{
    return Task::create([
        'title' => 'Task Papan Rencana '.uniqid(),
        'start_date' => now()->startOfWeek()->toDateString(),
        'due_date' => now()->startOfWeek()->addDays(3)->toDateString(),
        'status' => \App\Enums\TaskStatus::InProgress->value,
        'priority' => 'normal',
        'created_by' => admin()->id,
    ]);
}

it('renders the planner as a grid of days, not a Gantt chart', function () {
    $task = plannerTask();

    $this->actingAs(admin())
        ->get(route('tasks.index'))
        ->assertOk()
        ->assertSee('Papan Rencana')
        ->assertSee($task->title)
        ->assertSee(route('tasks.check', $task), false);
});

it('toggles a day on and off', function () {
    $task = plannerTask();
    $date = now()->startOfWeek()->addDay()->toDateString();

    $this->actingAs(admin())
        ->post(route('tasks.check', $task), ['date' => $date])
        ->assertRedirect();

    expect(TaskCheck::where('task_id', $task->id)->count())->toBe(1);

    $this->actingAs(admin())
        ->post(route('tasks.check', $task), ['date' => $date])
        ->assertRedirect();

    expect(TaskCheck::where('task_id', $task->id)->count())->toBe(0);
});

it('does not let a day be ticked before the task starts', function () {
    $task = plannerTask();

    $this->actingAs(admin())
        ->from(route('tasks.index'))
        ->post(route('tasks.check', $task), ['date' => $task->start_date->copy()->subDay()->toDateString()])
        ->assertSessionHasErrors('date');

    expect(TaskCheck::where('task_id', $task->id)->count())->toBe(0);
});

it('keeps the ticks when the deadline moves', function () {
    $task = plannerTask();
    $date = $task->start_date->copy()->addDay()->toDateString();

    $this->actingAs(admin())->post(route('tasks.check', $task), ['date' => $date]);

    // The plan is not the record of what happened: shrinking the window must
    // not erase a day somebody reported working.
    $task->update(['due_date' => $task->start_date->toDateString()]);

    expect(TaskCheck::where('task_id', $task->id)->whereDate('date', $date)->exists())->toBeTrue();
});

/* -----------------------------------------------------------------
 | Reports, one per module
 * ----------------------------------------------------------------- */

it('keeps each module report reachable and separate', function () {
    foreach (['reports.index', 'reports.tickets', 'reports.students', 'reports.tasks'] as $route) {
        $this->actingAs(admin())->get(route($route))->assertOk();
    }

    // The ticket report must not carry the student workload table, and vice
    // versa — that split is the whole point.
    $this->actingAs(admin())->get(route('reports.tickets'))
        ->assertDontSee('Statistik per Operator');

    $this->actingAs(admin())->get(route('reports.students'))
        ->assertDontSee('Rincian per status');
});

it('hides the reports from a role that may not read them', function () {
    $operator = operatorNamed('gen-noreport@test.local');

    foreach (['reports.index', 'reports.tickets', 'reports.students', 'reports.tasks'] as $route) {
        $this->actingAs($operator)->get(route($route))->assertForbidden();
    }
});

/* -----------------------------------------------------------------
 | Follow up lives in the ticket only
 * ----------------------------------------------------------------- */

it('keeps ticket follow-up, alongside the inbox follow-up for comments not yet ticketed', function () {
    // Interactions take follow-ups again until they become a ticket (see
    // CrmInboxTest); a ticket's own follow-up route is unchanged.
    expect(\Illuminate\Support\Facades\Route::has('tickets.followUp'))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Route::has('interactions.followUp'))->toBeTrue();
});

it('lets one ticket carry unlimited follow ups', function () {
    $ticket = Ticket::createWithNumber([
        'source' => 'manual',
        'subject' => 'Tiket banyak follow up',
        'created_by' => admin()->id,
    ]);

    $service = app(\App\Services\Tickets\TicketService::class);

    for ($i = 1; $i <= 6; $i++) {
        $service->addFollowUp($ticket->refresh(), [
            'action' => 'chat_wa',
            'response_text' => "Follow up ke-{$i}",
            'status' => \App\Enums\FollowUpStatus::OnProses->value,
        ], admin());
    }

    // Appended, never overwritten: the count on the ticket and the rows in the
    // table have to agree, or the history is lying about itself.
    expect($ticket->refresh()->follow_up_count)->toBe(6)
        ->and($ticket->followUps()->count())->toBe(6)
        ->and($ticket->followUps()->pluck('response_text')->first())->toBe('Follow up ke-1');
});
