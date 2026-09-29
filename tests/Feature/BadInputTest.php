<?php

/**
 * Query strings a person can actually produce, on every filterable screen.
 *
 * A read-only page must degrade to its default view, never to a 500. The one
 * that did: /reports/tasks?from=2030-99-99 — Carbon::parse() throws on an
 * impossible date, and a date typed by hand or produced by a picker in another
 * locale is exactly what arrives there.
 *
 * Kept as a sweep rather than one test per case: the next filter someone adds
 * is covered without anyone remembering to write a test for it.
 */

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

/** Nonsense a user can type, paste, or leave behind in a bookmark. */
function rubbishValues(): array
{
    return [
        '2030-99-99',        // impossible date
        'bukan-tanggal',     // not a date at all
        'abc',               // text where an id is expected
        '-1',
        '999999999',
        "' OR 1=1--",        // the classic
        '<script>alert(1)</script>',
        '',
    ];
}

/** Every screen that reads something off the query string. */
function filterableScreens(): array
{
    return [
        'students' => ['q', 'kondisi', 'operator', 'per_page', 'import', 'kabupaten', 'assignment'],
        'students/unsigned' => ['q', 'kondisi', 'import', 'kabupaten', 'kecamatan', 'per_page'],
        'tickets' => ['q', 'status', 'source', 'category', 'operator', 'flag', 'kabupaten'],
        'tasks' => ['q', 'status', 'priority', 'pic', 'from', 'to', 'visibility'],
        'interactions' => ['q', 'tab', 'channel', 'sentiment', 'status', 'handler'],
        'reports/tickets' => ['source', 'stage', 'from', 'to', 'kabupaten'],
        'reports/tasks' => ['from', 'to'],
        'reports/students' => ['q'],
        'contacts' => ['q'],
        'activities' => ['q'],
        'monitoring/comments' => ['q'],
    ];
}

it('never answers a rubbish filter with a server error', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();
    $failures = [];

    foreach (filterableScreens() as $uri => $params) {
        foreach ($params as $param) {
            foreach (rubbishValues() as $value) {
                $response = $this->actingAs($admin)->get('/'.$uri.'?'.$param.'='.urlencode($value));

                if ($response->getStatusCode() >= 500) {
                    $failures[] = "/{$uri}?{$param}=".$value;
                }
            }
        }
    }

    expect($failures)->toBe([]);
});

it('escapes whatever was typed into a search box', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    foreach (['students', 'tickets', 'interactions', 'contacts'] as $uri) {
        $this->actingAs($admin)
            ->get('/'.$uri.'?q='.urlencode('<script>alert(1)</script>'))
            ->assertOk()
            // Echoed back into the input's value, so it has to come back
            // escaped or the search box is a stored-XSS delivery mechanism.
            ->assertDontSee('<script>alert(1)</script>', false);
    }
});

it('falls back to the default window when a report date is impossible', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    // Not just "does not crash": it has to show the month, not an empty page.
    $this->actingAs($admin)
        ->get('/reports/tasks?from=2030-99-99&to=juga-bukan')
        ->assertOk()
        ->assertSee(now()->startOfMonth()->toDateString())
        ->assertSee(now()->endOfMonth()->toDateString());
});

it('clamps a page size nobody offered back to the default', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    // An allowlist, so ?per_page=999999 cannot ask the server to render 7.400
    // rows into one page. The rejected value does travel on in the pagination
    // links — harmless, because it is re-clamped on every request — so the
    // thing to assert is the size actually used, which the select reports.
    foreach (['999999', 'abc', '-5'] as $value) {
        $this->actingAs($admin)
            ->get('/students?per_page='.$value)
            ->assertOk()
            ->assertSee('<option value="25" selected>25</option>', false);
    }
});
