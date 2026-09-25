<?php

use App\Enums\TicketStatus;
use App\Models\Student;
use App\Models\Ticket;
use Database\Seeders\TicketDemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

/**
 * The example tickets exist to be looked at, so what matters is that they
 * render on the screen they were made for and that they can be taken away
 * again without leaving anything behind.
 */
it('seeds three readable examples and shows them on the ticket list', function () {
    Ticket::withTrashed()->where('extra->demo', true)->forceDelete();

    $this->seed(TicketDemoSeeder::class);

    $demo = Ticket::where('extra->demo', true)->get();

    expect($demo)->toHaveCount(3)
        // One per origin, so the list demonstrates all three doors in.
        ->and($demo->pluck('source.value')->sort()->values()->all())
        ->toBe(['import_mahasiswa', 'instagram', 'manual'])
        // And one already finished, so the Closed filter is not empty.
        ->and($demo->firstWhere('status', TicketStatus::Closed))->not->toBeNull()
        ->and($demo->firstWhere('status', TicketStatus::Closed)->resolution_note)->not->toBeEmpty()
        // Every example carries its follow-up history.
        ->and($demo->every(fn ($t) => $t->followUps()->count() > 0))->toBeTrue();

    // One request per example, filtered to it: with real tickets in the
    // database the three demos are not all on page 1.
    foreach ($demo as $ticket) {
        $this->actingAs(admin())
            ->get(route('tickets.index', ['q' => $ticket->number]))
            ->assertOk()
            ->assertSee($ticket->number);
    }
});

it('hangs the student example on a made-up student, never a real one', function () {
    Ticket::withTrashed()->where('extra->demo', true)->forceDelete();

    $this->seed(TicketDemoSeeder::class);

    $ticket = Ticket::where('extra->demo', true)->whereNotNull('student_id')->firstOrFail();

    // Invented follow-ups on a real person's record read as genuine to the
    // next operator who opens it, so the example must not touch one.
    expect($ticket->student->nim)->toBe(TicketDemoSeeder::DEMO_NIM)
        ->and($ticket->student->extra['demo'] ?? null)->toBeTrue()
        ->and($ticket->student->assigned_to)->toBeNull();
});

it('leaves nothing behind when cleared', function () {
    Ticket::withTrashed()->where('extra->demo', true)->forceDelete();
    Student::withTrashed()->where('nim', TicketDemoSeeder::DEMO_NIM)->forceDelete();

    $before = Student::count();

    $this->artisan('tickets:demo')->assertSuccessful();
    $this->artisan('tickets:demo --clear')->assertSuccessful();

    expect(Ticket::withTrashed()->where('extra->demo', true)->count())->toBe(0)
        ->and(Student::withTrashed()->where('nim', TicketDemoSeeder::DEMO_NIM)->count())->toBe(0)
        ->and(Student::count())->toBe($before);
});
