<?php

use App\Enums\{ContactStatus, RoleName};
use App\Models\{Activity, Contact, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

/**
 * Laravel's stock paginator ships grey buttons with a blue focus ring and
 * English labels — three things that are wrong in an app built on slate,
 * violet and Indonesian. The override lives in
 * resources/views/vendor/pagination/tailwind.blade.php.
 *
 * These pin the behaviour that is easy to lose: publishing a package, running
 * an upgrade, or someone calling ->onEachSide() would quietly restore the
 * default and nobody would notice until a screenshot.
 */
function renderPagination(int $perPage = 3, int $page = 1): string
{
    Paginator::currentPageResolver(fn () => $page);

    $paginator = Activity::paginate($perPage);
    $paginator->setPath('/activities');

    return $paginator->links()->render();
}

beforeEach(function () {
    // Enough rows to span several pages regardless of what else is seeded.
    $user = User::first();

    foreach (range(1, 12) as $i) {
        Activity::create([
            'user_id' => $user->id,
            'action' => 'test.paginate',
            'description' => "Baris uji {$i}",
        ]);
    }
});

it('speaks Indonesian, not English', function () {
    $html = renderPagination();

    expect($html)->toContain('Menampilkan')
        ->and($html)->not->toContain('Showing')
        ->and($html)->not->toContain('Previous')
        ->and($html)->not->toContain('Next');
});

it('uses the app palette instead of the stock greys', function () {
    $html = renderPagination();

    expect($html)->toContain('pg-link')
        ->and($html)->toContain('pg-arrow')
        // The stock view's tell-tale classes.
        ->and($html)->not->toContain('bg-gray-100')
        ->and($html)->not->toContain('focus:border-blue-300');
});

it('says which rows are on screen, not just which page', function () {
    // Page 2 of 3-per-page starts at row 4.
    $html = renderPagination(perPage: 3, page: 2);

    expect($html)->toContain('>4</span>')
        ->and($html)->toContain('>6</span>');
});

it('marks the current page for screen readers', function () {
    $html = renderPagination(perPage: 3, page: 2);

    expect($html)->toContain('aria-current="page"')
        ->and($html)->toContain('aria-label="Navigasi halaman"');
});

it('disables the arrow at each end rather than hiding it', function () {
    // A vanishing arrow shifts the whole row sideways between pages.
    $first = renderPagination(perPage: 3, page: 1);

    expect($first)->toContain('pg-disabled')
        ->and($first)->toContain('aria-disabled="true"');
});

it('still reports the count when everything fits on one page', function () {
    // The answer people want after filtering is "how many?", and an empty
    // space is not an answer.
    $html = renderPagination(perPage: 500);

    expect($html)->toContain('Menampilkan seluruh')
        ->and($html)->not->toContain('Navigasi halaman');
});

it('renders nothing at all when there is no data', function () {
    Activity::query()->delete();

    expect(trim(renderPagination()))->toBe('');
});

it('shows a compact position indicator for narrow screens', function () {
    $html = renderPagination(perPage: 3, page: 2);

    // A row of page numbers wraps badly on a phone; the position reads better.
    expect($html)->toContain('sm:hidden');
});

it('keeps the pagination on the pages that carry no other count', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    // A row on each page first.
    //
    // The pagination component renders nothing when there is no data, which is
    // correct and has its own test above. So on an emptied database this test
    // and that one contradicted each other, and this one lost — it was
    // asserting "the summary is always there" against three pages that had
    // nothing to summarise.
    DB::table('activities')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $admin->id,
        'action' => 'uji.pagination',
        'description' => 'Baris untuk menguji ringkasan halaman',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\UjiPagination',
        'notifiable_type' => User::class,
        'notifiable_id' => $admin->id,
        'data' => json_encode(['message' => 'Uji ringkasan halaman']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // An agent is defined by status, not by agent_since -- scopeAgents()
    // filters on ContactStatus::Agent, and a contact with only the date set
    // never appears on the page.
    Contact::query()->whereNull('merged_into_id')->limit(1)->update([
        'status' => ContactStatus::Agent->value,
        'agent_code' => 'UJI-01',
        'agent_since' => now(),
    ]);

    foreach (['activities.index', 'notifications.index', 'contacts.agents'] as $route) {
        $this->actingAs($admin)
            ->get(route($route))
            ->assertOk()
            ->assertSee('Menampilkan');
    }
});
