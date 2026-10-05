<?php

/**
 * /students/unsigned picks operators exactly as ticketing does, and handing a
 * student to someone hands them that student's open tickets too.
 */

use App\Enums\AssignmentStatus;
use App\Enums\RoleName;
use App\Enums\StudentCondition;
use App\Enums\TicketStatus;
use App\Models\Student;
use App\Models\Ticket;
use App\Models\TicketAssignment;
use App\Models\User;
use App\Services\Students\StudentTicketGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

/** @return \Illuminate\Support\Collection<int, Student> */
function handoverStudents(int $count = 3)
{
    return collect(range(1, $count))->map(fn ($i) => Student::create([
        'nim' => 'HOV'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
        'nama' => "Mahasiswa Handover {$i}",
        'kabupaten' => 'Kabupaten Handover',
        'kategori_masalah' => StudentCondition::OngoingBillingPending->value,
        'assignment_status' => AssignmentStatus::Unassigned->value,
    ]));
}

function handoverAdmin(): User
{
    return User::withRole(RoleName::SuperAdmin)->firstOrFail();
}

it('offers the same operators as the ticket assign form', function () {
    $followUp = User::withRole(RoleName::FollowUp)->firstOrFail();

    expect(User::ticketHandlerOptions()->pluck('id'))->toContain($followUp->id);

    $this->actingAs(handoverAdmin())
        ->get(route('students.unsigned'))
        ->assertOk()
        ->assertSee('<optgroup label="'.$followUp->role->label.'">', false)
        ->assertSee($followUp->name);
});

it('passes the student\'s open tickets to the operator they are given', function () {
    $students = handoverStudents();
    app(StudentTicketGenerator::class)->generate(['q' => 'HOV000'], handoverAdmin());

    $tickets = Ticket::whereIn('student_id', $students->pluck('id'))->get();
    expect($tickets)->toHaveCount(3)
        ->and($tickets->pluck('assigned_to')->filter()->all())->toBe([]);

    $followUp = User::withRole(RoleName::FollowUp)->firstOrFail();

    $this->actingAs(handoverAdmin())->post(route('students.assign.selected'), [
        'students' => $students->pluck('id')->all(),
        'operator_id' => $followUp->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $tickets = Ticket::whereIn('student_id', $students->pluck('id'))->get();

    expect($tickets->pluck('assigned_to')->unique()->all())->toBe([$followUp->id])
        ->and($tickets->pluck('status')->unique()->all())->toBe([TicketStatus::Assigned])
        ->and(TicketAssignment::whereIn('ticket_id', $tickets->pluck('id'))->where('to_user_id', $followUp->id)->count())->toBe(3);

    // And the follow-up user can now work them.
    $this->actingAs($followUp)->get(route('tickets.show', $tickets->first()))->assertOk();
});

it('leaves closed tickets where they are', function () {
    $students = handoverStudents(1);
    app(StudentTicketGenerator::class)->generate(['q' => 'HOV000'], handoverAdmin());

    $ticket = Ticket::where('student_id', $students->first()->id)->firstOrFail();
    $ticket->forceFill(['status' => TicketStatus::Closed->value])->save();

    $this->actingAs(handoverAdmin())->post(route('students.assign.selected'), [
        'students' => [$students->first()->id],
        'operator_id' => User::withRole(RoleName::Operator)->firstOrFail()->id,
    ]);

    expect($ticket->refresh()->assigned_to)->toBeNull();
});

it('validates the operator the same way ticketing does', function () {
    $students = handoverStudents(1);

    $this->actingAs(handoverAdmin())->post(route('students.assign.selected'), [
        'students' => [$students->first()->id],
        'operator_id' => User::withRole(RoleName::Creative)->firstOrFail()->id,
    ])->assertSessionHasErrors('operator_id');

    $this->actingAs(handoverAdmin())->post(route('tickets.store'), [
        'subject' => 'Uji operator', 'source' => 'manual', 'priority' => 'normal',
        'assigned_to' => User::withRole(RoleName::Creative)->firstOrFail()->id,
    ])->assertSessionHasErrors('assigned_to');
});
