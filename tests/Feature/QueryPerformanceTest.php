<?php
use App\Enums\{ContentStatus, RoleName, SocialPlatform};
use App\Models\{Content, SocialAccount, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

/** Seed enough rows that an N+1 would show up clearly. */
function seedContents(int $n, ContentStatus $status): void {
    $creative = User::withRole(RoleName::Creative)->firstOrFail();
    $account = SocialAccount::firstOrCreate(
        ['platform' => SocialPlatform::Instagram, 'external_id' => 'perf-test'],
        ['name' => 'Perf IG', 'username' => 'perf', 'is_active' => true],
    );

    for ($i = 0; $i < $n; $i++) {
        $c = Content::create([
            'title' => "Perf {$i}", 'status' => $status, 'created_by' => $creative->id,
            'scheduled_at' => now()->addDays($i),
        ]);
        $c->media()->create(['type' => 'image', 'disk' => 'public', 'path' => "p{$i}.jpg", 'size' => 1]);
        $c->schedules()->create([
            'social_account_id' => $account->id, 'scheduled_at' => now()->addDays($i), 'status' => $status,
        ]);
    }
}

function countQueries(callable $fn): int {
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    return $count;
}

it('keeps the content list query count flat as rows grow', function () {
    $user = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    seedContents(3, ContentStatus::Draft);
    $few = countQueries(fn () => $this->actingAs($user)->get(route('contents.index'))->assertOk());

    seedContents(12, ContentStatus::Draft);
    $many = countQueries(fn () => $this->actingAs($user)->get(route('contents.index'))->assertOk());

    // Eager loading means more rows must not mean more queries.
    expect($many)->toBeLessThanOrEqual($few + 1)
        ->and($many)->toBeLessThan(20);
});

it('keeps the approval queue query count flat', function () {
    $curator = User::withRole(RoleName::Curator)->firstOrFail();

    seedContents(3, ContentStatus::WaitingApproval);
    $few = countQueries(fn () => $this->actingAs($curator)->get(route('approvals.index'))->assertOk());

    seedContents(12, ContentStatus::WaitingApproval);
    $many = countQueries(fn () => $this->actingAs($curator)->get(route('approvals.index'))->assertOk());

    expect($many)->toBeLessThanOrEqual($few + 1);
});

it('keeps the activity log query count flat', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();
    seedContents(10, ContentStatus::Draft);

    $count = countQueries(fn () => $this->actingAs($admin)->get(route('activities.index'))->assertOk());
    expect($count)->toBeLessThan(15);
});

/* -----------------------------------------------------------------
 | New modules: the list screens must not grow queries with rows.
 * ----------------------------------------------------------------- */

/** Students, each assigned to an operator so the relation is exercised. */
function seedPerfStudents(int $n): void
{
    $operator = operatorNamed('perf-op@test.local');

    for ($i = 0; $i < $n; $i++) {
        \App\Models\Student::create([
            'nim' => 'PERF'.str_pad((string) $i, 6, '0', STR_PAD_LEFT).uniqid(),
            'nama' => "Perf Mahasiswa {$i}",
            'kabupaten' => 'Bangka',
            'kecamatan' => 'Sungailiat',
            'kategori_masalah' => 'ongoing_tidak_registrasi',
            'assignment_status' => \App\Enums\AssignmentStatus::Assigned->value,
            'assigned_to' => $operator->id,
        ]);
    }
}

/** Tickets with a category, an assignee and a follow-up each. */
function seedPerfTickets(int $n): void
{
    $service = app(\App\Services\Tickets\TicketService::class);
    $category = \App\Models\TicketCategory::roots()->first();
    $operator = operatorNamed('perf-op@test.local');

    for ($i = 0; $i < $n; $i++) {
        $ticket = $service->createManual([
            'subject' => "Perf tiket {$i}",
            'category_id' => $category?->id,
            'sub_category_id' => $category?->children()->value('id'),
        ], admin());

        $service->assign($ticket, $operator, admin());
        $service->addFollowUp($ticket, ['action' => 'ditelepon'], admin());
    }
}

it('keeps the student list query count flat as rows grow', function () {
    $user = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    seedPerfStudents(3);
    $few = countQueries(fn () => $this->actingAs($user)->get(route('students.index'))->assertOk());

    seedPerfStudents(15);
    $many = countQueries(fn () => $this->actingAs($user)->get(route('students.index'))->assertOk());

    expect($many)->toBeLessThanOrEqual($few + 1)
        ->and($many)->toBeLessThan(25);
});

it('keeps the ticket list query count flat as rows grow', function () {
    $user = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    seedPerfTickets(3);
    $few = countQueries(fn () => $this->actingAs($user)->get(route('tickets.index'))->assertOk());

    seedPerfTickets(10);
    $many = countQueries(fn () => $this->actingAs($user)->get(route('tickets.index'))->assertOk());

    // category, subCategory and assignee are eager-loaded, so 10 more tickets
    // must not mean 30 more queries.
    expect($many)->toBeLessThanOrEqual($few + 1)
        ->and($many)->toBeLessThan(25);
});

it('keeps the ticket detail query count flat as follow-ups grow', function () {
    $user = User::withRole(RoleName::SuperAdmin)->firstOrFail();
    $service = app(\App\Services\Tickets\TicketService::class);

    $ticket = $service->createManual(['subject' => 'Perf detail'], admin());
    $service->addFollowUp($ticket, ['action' => 'ditelepon'], admin());

    $few = countQueries(fn () => $this->actingAs($user)->get(route('tickets.show', $ticket))->assertOk());

    for ($i = 0; $i < 10; $i++) {
        $service->addFollowUp($ticket->refresh(), ['action' => 'ditelepon'], admin());
        $service->saveDetail($ticket, ['nim' => 'PERFD'.$i.uniqid()], admin());
    }

    $many = countQueries(fn () => $this->actingAs($user)->get(route('tickets.show', $ticket))->assertOk());

    // followUps.user and details.student are eager-loaded; the history growing
    // must not add a query per row.
    expect($many)->toBeLessThanOrEqual($few + 2)
        ->and($many)->toBeLessThan(35);
});

it('keeps the task timeline query count flat as tasks grow', function () {
    $user = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    $make = function (int $n) use ($user) {
        for ($i = 0; $i < $n; $i++) {
            \App\Models\Task::create([
                'title' => 'Perf task '.uniqid(),
                'start_date' => now()->startOfWeek(),
                'due_date' => now()->startOfWeek()->addDays(3),
                'status' => \App\Enums\TaskStatus::Planned->value,
                'priority' => 'normal',
                'pic_id' => $user->id,
            ]);
        }
    };

    $make(3);
    $few = countQueries(fn () => $this->actingAs($user)->get(route('tasks.index'))->assertOk());

    $make(12);
    $many = countQueries(fn () => $this->actingAs($user)->get(route('tasks.index'))->assertOk());

    expect($many)->toBeLessThanOrEqual($few + 1)
        ->and($many)->toBeLessThan(25);
});

/* -----------------------------------------------------------------
 | Cached filter dropdowns
 |
 | Four DISTINCT scans over the whole student table ran on every page load.
 | Caching them is only safe if the cache actually clears when the data moves,
 | so both halves are tested.
 * ----------------------------------------------------------------- */

it('does not rescan the table for filter options on every page load', function () {
    \Illuminate\Support\Facades\Cache::flush();
    seedPerfStudents(5);

    $stats = app(\App\Services\Students\StudentStats::class);

    // First call populates, second must not touch `students` at all.
    $stats->kabupatenOptions();

    $hitStudents = 0;
    \Illuminate\Support\Facades\DB::listen(function ($q) use (&$hitStudents) {
        if (str_contains($q->sql, 'from `students`')) {
            $hitStudents++;
        }
    });

    $stats->kabupatenOptions();

    expect($hitStudents)->toBe(0);
});

it('shows a newly imported kabupaten in the filter immediately', function () {
    \Illuminate\Support\Facades\Cache::flush();

    $stats = app(\App\Services\Students\StudentStats::class);
    $before = $stats->kabupatenOptions();

    \App\Models\Student::create([
        'nim' => 'CACHE'.uniqid(),
        'nama' => 'Mahasiswa Kabupaten Baru',
        'kabupaten' => 'Kab. Sangat Baru '.uniqid(),
        'kategori_masalah' => 'lainnya',
    ]);

    // Still stale — nothing has told the cache otherwise.
    expect($stats->kabupatenOptions())->toBe($before);

    // The import and the edit screens both call this; after it, the new value
    // must be selectable rather than waiting an hour for the TTL.
    $stats->forgetOptions();

    expect($stats->kabupatenOptions())->toHaveCount(count($before) + 1);
});
