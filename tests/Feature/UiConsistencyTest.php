<?php

/**
 * Whole-UI sweeps, run for every role.
 *
 * These catch the class of bug that a per-feature test misses: a button that
 * renders for someone who is not allowed to press it, a link to a route that
 * no longer exists, or developer instructions leaking onto a user's screen.
 */

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;

uses(DatabaseTransactions::class);

/** Every parameterless GET page a logged-in user can land on. */
function browsablePages(): array
{
    return collect(Route::getRoutes())
        ->filter(fn ($r) => in_array('GET', $r->methods(), true))
        ->filter(fn ($r) => ! str_contains($r->uri(), '{'))
        ->filter(fn ($r) => ! in_array($r->uri(), ['/', 'login', 'up', 'storage/{path}'], true))
        ->map(fn ($r) => $r->uri())
        ->unique()
        ->values()
        ->all();
}

it('never shows a write button to a role that cannot use it', function () {
    /*
     * Each entry: the form action to look for, and the permission the
     * controller demands for it. A page that renders the form without the
     * permission is a 403 waiting to happen — the user presses a button that
     * the server then refuses, with no explanation on screen.
     */
    $guarded = [
        'students/assign/region' => \App\Enums\Permission::AssignStudents,
        'students/generate-tickets' => \App\Enums\Permission::CreateTickets,
        'students/assign/selected' => \App\Enums\Permission::AssignStudents,
        'students/import' => \App\Enums\Permission::ImportStudents,
        'tickets/categories' => \App\Enums\Permission::ManageTicketCategories,
    ];

    $failures = [];

    foreach (RoleName::cases() as $roleName) {
        $user = User::withRole($roleName)->first();

        if (! $user) {
            continue;
        }

        foreach (browsablePages() as $uri) {
            $response = $this->actingAs($user)->get('/'.$uri);

            if ($response->getStatusCode() !== 200) {
                continue;
            }

            $html = $response->getContent();

            foreach ($guarded as $action => $permission) {
                $rendersForm = str_contains($html, 'action="'.url($action).'"');

                if ($rendersForm && ! $user->hasPermission($permission)) {
                    $failures[] = "{$roleName->value} sees {$action} on /{$uri} without {$permission->value}";
                }
            }
        }
    }

    expect($failures)->toBe([]);
});

it('never prints a terminal command where a field worker can see it', function () {
    /*
     * Scoped to the roles that work cases rather than run the server.
     *
     * "php artisan …" is fine on the dashboard's system-health panel: that is
     * shown only to people who hold ManageSettings or ViewAccounts, i.e. the
     * ones who can actually add a cron entry. Telling an OPERATOR to open a
     * terminal is a dead end, and that is what this guards.
     */
    $offenders = [];

    foreach ([RoleName::Operator, RoleName::Pic] as $roleName) {
        $user = User::withRole($roleName)->first();

        if (! $user) {
            continue;
        }

        foreach (browsablePages() as $uri) {
            $response = $this->actingAs($user)->get('/'.$uri);

            if ($response->getStatusCode() !== 200) {
                continue;
            }

            if (str_contains($response->getContent(), 'php artisan')) {
                $offenders[] = "{$roleName->value} on /{$uri}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('renders every page for every role without a server error', function () {
    // Same guarantee as RouteMatrixTest, kept here because the new modules add
    // pages that read data the older sweep never exercised.
    $failures = [];

    foreach (RoleName::cases() as $roleName) {
        $user = User::withRole($roleName)->first();

        if (! $user) {
            continue;
        }

        foreach (browsablePages() as $uri) {
            $status = $this->actingAs($user)->get('/'.$uri)->getStatusCode();

            if ($status >= 500) {
                $failures[] = "{$roleName->value} GET /{$uri} => {$status}";
            }
        }
    }

    expect($failures)->toBe([]);
});

it('keeps jadwal kegiatan behind a login now', function () {
    $this->get(route('public.tasks'))->assertRedirect(route('login'));
});

it('keeps the guest book form and monitor reachable without logging in', function () {
    $this->get(route('guest-book.create'))->assertOk();
    $this->get(route('guest-book.monitor'))->assertOk();
    $this->getJson(route('guest-book.monitor.feed'))->assertOk()->assertJsonStructure(['now', 'waiting', 'served_today']);
});

it('sends a guest to the login page, not to an error', function () {
    foreach (['students', 'tickets', 'tasks', 'reports'] as $uri) {
        $this->get('/'.$uri)->assertRedirect(route('login'));
    }
});

it('offers the light/dark switch on every kind of page', function () {
    // Public pages and the login page: the floating toggle.
    foreach ([route('login'), route('guest-book.create')] as $url) {
        $this->get($url)->assertOk()->assertSee('$store.theme.toggle()', false);
    }

    // The monitor has its own in the header — exactly one, not two.
    $monitor = $this->get(route('guest-book.monitor'))->assertOk()->getContent();
    expect(substr_count($monitor, '$store.theme.toggle()'))->toBe(1);

    // Signed-in pages: the topbar.
    $this->actingAs(\App\Models\User::withRole(\App\Enums\RoleName::SuperAdmin)->firstOrFail())
        ->get(route('dashboard'))->assertOk()->assertSee('$store.theme.toggle()', false);
});

it('shows error pages in Indonesian, with the theme switch, without the build', function () {
    $html = $this->get('/halaman-yang-tidak-ada')->assertNotFound()->getContent();

    expect($html)
        ->toContain('Halaman tidak ditemukan')
        ->toContain('id="theme-toggle"')
        ->not->toContain('/build/assets/');
});
