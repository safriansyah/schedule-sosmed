<?php

/**
 * The planner's two additions: ticking cells without a page reload, and the
 * morning reminder that stops a deadline sailing past unread.
 */

use App\Enums\Priority;
use App\Enums\RoleName;
use App\Enums\TaskStatus;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\User;
use App\Notifications\TaskReminderNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;

uses(DatabaseTransactions::class);

function aTask(array $overrides = []): Task
{
    return Task::create(array_merge([
        'title' => 'Task Uji Papan '.uniqid(),
        'start_date' => now()->startOfWeek()->toDateString(),
        'due_date' => now()->startOfWeek()->addDays(3)->toDateString(),
        'status' => TaskStatus::InProgress->value,
        'priority' => Priority::Normal->value,
        'created_by' => admin()->id,
    ], $overrides));
}

/* -----------------------------------------------------------------
 | Ticking a cell in the background
 * ----------------------------------------------------------------- */

it('answers a tick with JSON when the page asks for it', function () {
    $task = aTask();
    $date = $task->start_date->copy()->addDay()->toDateString();

    $this->actingAs(admin())
        ->postJson(route('tasks.check', $task), ['date' => $date])
        ->assertOk()
        ->assertJson(['checked' => true, 'by' => admin()->name]);

    // And the same call again turns it off.
    $this->actingAs(admin())
        ->postJson(route('tasks.check', $task), ['date' => $date])
        ->assertOk()
        ->assertJson(['checked' => false]);

    expect(TaskCheck::where('task_id', $task->id)->count())->toBe(0);
});

it('refuses an impossible date with a status the page can act on', function () {
    $task = aTask();

    // 422, not a redirect: the grid needs to know the tick failed so it can
    // put the cell back rather than leave it looking saved.
    $this->actingAs(admin())
        ->postJson(route('tasks.check', $task), [
            'date' => $task->start_date->copy()->subDay()->toDateString(),
        ])
        ->assertStatus(422)
        ->assertJson(['checked' => false]);
});

it('still works as a plain form post without JavaScript', function () {
    $task = aTask();

    $this->actingAs(admin())
        ->post(route('tasks.check', $task), ['date' => $task->start_date->toDateString()])
        ->assertRedirect();

    expect(TaskCheck::where('task_id', $task->id)->count())->toBe(1);
});

it('shows who ticked a day on the task page', function () {
    $task = aTask();

    $this->actingAs(admin())->post(route('tasks.check', $task), [
        'date' => $task->start_date->toDateString(),
    ]);

    $this->actingAs(admin())
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertSee('Aktivitas Harian')
        ->assertSee(admin()->name)
        ->assertSee($task->start_date->translatedFormat('l, j F Y'));
});

/* -----------------------------------------------------------------
 | The morning reminder
 * ----------------------------------------------------------------- */

it('tells a PIC about their late task', function () {
    Notification::fake();
    Setting::forget('tasks.remind.last_date');

    $pic = operatorNamed('planner-pic@test.local');

    aTask([
        'pic_id' => $pic->id,
        'start_date' => now()->subDays(10)->toDateString(),
        'due_date' => now()->subDays(3)->toDateString(),
    ]);

    $this->artisan('tasks:remind')->assertSuccessful();

    Notification::assertSentTo($pic, TaskReminderNotification::class, function ($n) {
        return $n->overdue === 1 && ! $n->unowned;
    });
});

it('sends one digest per person, not one per task', function () {
    Notification::fake();
    Setting::forget('tasks.remind.last_date');

    $pic = operatorNamed('planner-pic2@test.local');

    // A bell that floods is a bell nobody reads, and it is shared with the
    // urgent-comment alerts.
    foreach (range(1, 4) as $i) {
        aTask([
            'pic_id' => $pic->id,
            'start_date' => now()->subDays(10)->toDateString(),
            'due_date' => now()->subDays($i)->toDateString(),
        ]);
    }

    $this->artisan('tasks:remind')->assertSuccessful();

    Notification::assertSentToTimes($pic, TaskReminderNotification::class, 1);
});

it('sends an unowned task to the people who can hand it out', function () {
    Notification::fake();
    Setting::forget('tasks.remind.last_date');

    aTask([
        'pic_id' => null,
        'start_date' => now()->subDays(10)->toDateString(),
        'due_date' => now()->subDay()->toDateString(),
    ]);

    $this->artisan('tasks:remind')->assertSuccessful();

    $manager = User::withRole(RoleName::Manager)->first();
    $operator = User::withRole(RoleName::Operator)->first();

    Notification::assertSentTo($manager, TaskReminderNotification::class, fn ($n) => $n->unowned);

    // Telling every operator about a decision only a manager can make is how
    // the bell stops being read.
    Notification::assertNotSentTo($operator, TaskReminderNotification::class);
});

it('leaves a completed task alone', function () {
    Notification::fake();
    Setting::forget('tasks.remind.last_date');

    $pic = operatorNamed('planner-pic3@test.local');

    aTask([
        'pic_id' => $pic->id,
        'status' => TaskStatus::Completed->value,
        'start_date' => now()->subDays(10)->toDateString(),
        'due_date' => now()->subDays(3)->toDateString(),
    ]);

    $this->artisan('tasks:remind')->assertSuccessful();

    Notification::assertNotSentTo($pic, TaskReminderNotification::class);
});

it('does not send twice in one day', function () {
    Notification::fake();
    Setting::forget('tasks.remind.last_date');

    $pic = operatorNamed('planner-pic4@test.local');

    aTask([
        'pic_id' => $pic->id,
        'start_date' => now()->subDays(10)->toDateString(),
        'due_date' => now()->subDays(2)->toDateString(),
    ]);

    // A scheduler restart inside the sending minute must not mean two bells.
    $this->artisan('tasks:remind')->assertSuccessful();
    $this->artisan('tasks:remind')->assertSuccessful();

    Notification::assertSentToTimes($pic, TaskReminderNotification::class, 1);

    // …unless someone deliberately asks for it.
    $this->artisan('tasks:remind --force')->assertSuccessful();

    Notification::assertSentToTimes($pic, TaskReminderNotification::class, 2);
});

it('is scheduled in-process, the way every other job here is', function () {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());

    $reminder = $events->first(fn ($e) => $e->description === 'task-reminders');

    expect($reminder)->not->toBeNull()
        ->and($reminder->expression)->toBe('30 7 * * *')
        // Schedule::command() spawns a subprocess through proc_open, which
        // plenty of shared hosts disable — it would fail silently there.
        ->and($reminder)->toBeInstanceOf(\Illuminate\Console\Scheduling\CallbackEvent::class);
});
