<?php

/**
 * Every readable page, opened by every role.
 *
 * The app has nine roles and roughly seventy GET routes. A page is allowed to
 * say 403 — that is the permission system working — and allowed to 404 on a
 * record that is not there. What it must never do is 500, and a permission
 * matrix is exactly the kind of thing where one role reaches a view that
 * assumes a variable another role's controller path never set.
 *
 * Written as a sweep rather than one test per page so a route added next month
 * is covered without anyone remembering to add it here.
 */

use App\Enums\{ContentStatus, RoleName, TicketSource, TicketStatus};
use App\Models\{AccountMedia, Contact, Content, Student, Task, Ticket, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;

uses(DatabaseTransactions::class);

/**
 * One record of each kind, so the DETAIL pages are actually opened.
 *
 * Without this the sweep quietly skipped /tickets/{id}, /students/{id},
 * /tasks/{id} and /contents/{id} whenever the database happened to be empty —
 * which is most of the time after a data:reset. It passed in a fraction of a
 * second and proved nothing about the pages most likely to break.
 */
beforeEach(function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    if (Ticket::count() === 0) {
        Ticket::create([
            'number' => 'TKT-SAPU-01',
            'subject' => 'Tiket untuk penyapuan rute',
            'description' => 'Dibuat oleh tes, dibatalkan bersama transaksinya.',
            'source' => TicketSource::Manual,
            'status' => TicketStatus::Open,
            'created_by' => $admin->id,
        ]);
    }

    if (Student::count() === 0) {
        Student::create(['nim' => '999999999', 'nama' => 'Mahasiswa Uji Sapu']);
    }

    if (Task::count() === 0) {
        Task::create([
            'title' => 'Tugas uji sapu',
            'start_date' => now()->toDateString(),
            'due_date' => now()->addWeek()->toDateString(),
            'created_by' => $admin->id,
        ]);
    }

    if (Content::count() === 0) {
        Content::create([
            'title' => 'Konten uji sapu',
            'status' => ContentStatus::Draft,
            'created_by' => $admin->id,
        ]);
    }
});

/**
 * Concrete values for the routes that take a parameter, so they are exercised
 * rather than skipped. A missing record leaves that route out entirely — a
 * 404 sweep would prove nothing.
 *
 * @return array<string, string|int|null>
 */
function sweepBindings(): array
{
    return [
        '{content}' => Content::value('id'),
        '{ticket}' => Ticket::value('id'),
        '{student}' => Student::value('id'),
        '{contact}' => Contact::value('id'),
        '{media}' => AccountMedia::value('id'),
        '{task}' => Task::value('id'),
        '{user}' => User::value('id'),
        '{username}' => 'utpangkalpinang',
    ];
}

/**
 * Static and single-parameter GET routes behind auth.
 *
 * @return array<int, string>
 */
function sweepRoutes(): array
{
    $bindings = sweepBindings();
    $uris = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $uri = '/'.ltrim($route->uri(), '/');

        // Public or infrastructure endpoints — not part of the role matrix.
        if (preg_match('#^/(login|register|up|storage|_|sanctum)#', $uri) || $uri === '/') {
            continue;
        }

        // Substitute what we can; drop anything still holding a placeholder.
        foreach ($bindings as $placeholder => $value) {
            if ($value !== null) {
                $uri = str_replace($placeholder, (string) $value, $uri);
            }
        }

        if (str_contains($uri, '{')) {
            continue;
        }

        $uris[] = $uri;
    }

    return array_values(array_unique($uris));
}

it('never answers a page with a server error, whoever opens it', function () {
    $routes = sweepRoutes();

    expect($routes)->not->toBeEmpty();

    $failures = [];

    foreach (RoleName::cases() as $role) {
        $user = User::withRole($role)->first();

        if (! $user) {
            continue;
        }

        foreach ($routes as $uri) {
            try {
                $status = $this->actingAs($user)->get($uri)->getStatusCode();
            } catch (Throwable $e) {
                $failures[] = "{$role->value} {$uri} -> ".get_class($e).': '.$e->getMessage();

                continue;
            }

            // 403 is the permission system doing its job; 404 is a record that
            // is simply not there. 5xx is the app breaking.
            if ($status >= 500) {
                $failures[] = "{$role->value} {$uri} -> HTTP {$status}";
            }
        }
    }

    expect($failures)->toBe([]);
});

it('gives every role a landing page it can actually open', function () {
    // A role that cannot open ANY page is a role nobody can use, and the login
    // redirect would drop them on a 403 immediately after signing in.
    $stuck = [];

    foreach (RoleName::cases() as $role) {
        $user = User::withRole($role)->first();

        if (! $user) {
            $stuck[] = "{$role->value} (tidak ada usernya)";

            continue;
        }

        if ($this->actingAs($user)->get('/dashboard')->getStatusCode() !== 200) {
            $stuck[] = $role->value;
        }
    }

    expect($stuck)->toBe([]);
});
