<?php

/**
 * The one command a deployer runs before handing the app over.
 *
 * What matters is not that it deletes — it is that it deletes ONLY the rows a
 * seeder marked. A blunt clear-out on a live server takes the institution's
 * data with it, and there is no undo for that.
 */

use App\Models\Student;
use App\Models\Task;
use App\Models\Ticket;
use Database\Seeders\TicketDemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('removes the marked example rows', function () {
    Ticket::withTrashed()->where('extra->demo', true)->forceDelete();
    $this->seed(TicketDemoSeeder::class);

    expect(Ticket::where('extra->demo', true)->count())->toBe(3);

    $this->artisan('demo:clear --force')->assertSuccessful();

    expect(Ticket::withTrashed()->where('extra->demo', true)->count())->toBe(0)
        ->and(Student::withTrashed()->where('nim', TicketDemoSeeder::DEMO_NIM)->count())->toBe(0);
});

it('leaves real rows alone', function () {
    // A student and a ticket with none of the seeders' markers — exactly what
    // the institution's own data looks like.
    $student = Student::create([
        'nim' => 'REAL'.random_int(100000, 999999),
        'nama' => 'Mahasiswa Sungguhan',
        'kabupaten' => 'Kab. Bangka',
        'kategori_masalah' => 'ongoing_billing_pending',
    ]);

    $ticket = Ticket::createWithNumber([
        'source' => 'manual',
        'subject' => 'Tiket sungguhan yang tidak boleh hilang',
        'student_id' => $student->id,
    ]);

    $task = Task::create([
        'title' => 'Task sungguhan '.uniqid(),
        'start_date' => now()->toDateString(),
        'due_date' => now()->addDays(3)->toDateString(),
        'status' => \App\Enums\TaskStatus::InProgress->value,
        'priority' => 'normal',
        'created_by' => admin()->id,
    ]);

    $before = [Student::count(), Ticket::count()];

    $this->artisan('demo:clear --force')->assertSuccessful();

    expect(Student::find($student->id))->not->toBeNull()
        ->and(Ticket::find($ticket->id))->not->toBeNull()
        ->and(Task::find($task->id))->not->toBeNull();

    // Only the demo rows moved, and this run had none of the ticket demos.
    expect(Student::count())->toBeLessThanOrEqual($before[0])
        ->and(Ticket::count())->toBeLessThanOrEqual($before[1]);
});

it('reports without deleting on a dry run', function () {
    Ticket::withTrashed()->where('extra->demo', true)->forceDelete();
    $this->seed(TicketDemoSeeder::class);

    $this->artisan('demo:clear --dry-run')->assertSuccessful();

    expect(Ticket::where('extra->demo', true)->count())->toBe(3);
});

it('reads the seeded NIMs from the seeder rather than a copy', function () {
    // A hard-coded copy would silently stop matching the day someone edits
    // StudentSeeder, leaving demo students behind on a production install.
    $nims = array_column((array) (new ReflectionClass(\Database\Seeders\StudentSeeder::class))->getConstant('DEMO'), 0);

    expect($nims)->not->toBeEmpty()
        ->and($nims[0])->toBeString();
});
