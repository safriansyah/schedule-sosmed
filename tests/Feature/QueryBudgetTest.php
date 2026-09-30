<?php

/**
 * How many queries each list page costs.
 *
 * An N+1 is invisible on a dev database with twelve rows and crippling on the
 * real one: the monitoring grid shows 12 posts out of 2.000, and the inbox
 * pages 25 comments out of thousands. A page that fires one extra query per row
 * still renders in milliseconds here and takes seconds there.
 *
 * The budgets below are deliberately loose. This is not a performance
 * benchmark — it is a tripwire for "someone added ->someRelation->name inside
 * a @foreach". A page that suddenly needs eighty queries has gained a loop;
 * one that needs twenty-two instead of twenty has not.
 */

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

/**
 * Count the queries one request fires.
 *
 * The collector is registered ONCE and cleared before each measurement.
 * Registering a fresh DB::listen per call leaves the earlier ones attached, and
 * the numbers that come back are then inflated by every previous page — which
 * is exactly how a first pass at this reported the dashboard at 266 queries
 * when it actually makes 38.
 *
 * @return array{count:int, queries:array<int, string>}
 */
function measureQueries(callable $request): array
{
    static $collected = [];
    static $listening = false;

    if (! $listening) {
        DB::listen(function ($query) use (&$collected) {
            $collected[] = $query->sql;
        });

        $listening = true;
    }

    $collected = [];

    $request();

    return ['count' => count($collected), 'queries' => $collected];
}

it('keeps the list pages inside a sane query budget', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    // Page => the most queries it may fire, set from what each page actually
    // costs today plus room to breathe. A flat generous number everywhere
    // caught nothing: /tickets needs seven, so a budget of forty would let it
    // grow a loop over every row and still pass.
    //
    // These do not scale with row count — the relations are eager loaded — so
    // a page that drifts upward has gained a query inside a @foreach.
    $budgets = [
        '/activities' => 15,            // 4 today
        '/tickets' => 20,               // 7
        '/monitoring/comments' => 20,   // 7
        '/contacts' => 20,              // 8
        '/tasks' => 20,                 // 8
        '/students' => 25,              // 10
        '/interactions' => 30,          // 15
        '/monitoring' => 40,            // 27 — several fixed period aggregates
        '/dashboard' => 55,             // 38 — many panels, each its own query
    ];

    $over = [];

    foreach ($budgets as $uri => $budget) {
        $result = measureQueries(function () use ($admin, $uri) {
            $this->actingAs($admin)->get($uri)->assertOk();
        });

        if ($result['count'] > $budget) {
            // Name the repeated statement, since that is the one to fix.
            $repeated = collect($result['queries'])
                ->countBy()
                ->sortDesc()
                ->take(2)
                ->map(fn ($n, $sql) => "{$n}x ".mb_substr($sql, 0, 110))
                ->implode(' | ');

            $over[] = "{$uri}: {$result['count']} kueri (batas {$budget}) — {$repeated}";
        }
    }

    expect($over)->toBe([]);
});

it('does not grow its query count when more rows are shown', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    // The clearest signal there is. If a page costs the same for 12 rows as for
    // 100, nothing inside the loop is querying; if it scales with the page
    // size, something is.
    $small = measureQueries(function () use ($admin) {
        $this->actingAs($admin)->get('/interactions?per_page=10')->assertOk();
    })['count'];

    $large = measureQueries(function () use ($admin) {
        $this->actingAs($admin)->get('/interactions?per_page=100')->assertOk();
    })['count'];

    expect($large)->toBeLessThanOrEqual($small + 5);
});
