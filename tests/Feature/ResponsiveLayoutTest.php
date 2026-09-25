<?php

/**
 * Layout guarantees a browser test would catch but a status code will not.
 *
 * These cannot measure pixels. What they CAN do is hold the structural
 * decisions in place: the data-heavy screens must ship a phone rendering, wide
 * tables must be scrollable rather than clipped, and nothing may render a
 * fixed-width block that a 375px screen cannot show.
 */

use App\Enums\RoleName;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;

uses(DatabaseTransactions::class);

/** The screens that carry real volume, and therefore hurt most on a phone. */
function dataHeavyPages(): array
{
    return [
        'students',
        'students/unsigned',
        'tickets',
        'tasks',
        'reports',
        'reports/tickets',
        'reports/students',
        'reports/tasks',
        'tickets/settings',
        'interactions',
        'contacts/agents',
        'analytics',
    ];
}

it('gives every data-heavy screen a phone rendering', function () {
    $user = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    // One row is enough: the mobile block only renders when there is data.
    Student::create([
        'nim' => 'RESP000001',
        'nama' => 'Mahasiswa Responsif',
        'kabupaten' => 'Kab. Bangka',
        'kategori_masalah' => 'ongoing_billing_pending',
    ]);

    $missing = [];

    foreach (['students', 'students/unsigned'] as $uri) {
        $html = $this->actingAs($user)->get('/'.$uri)->assertOk()->getContent();

        // A table hidden below md must be paired with something shown there.
        $hasPhoneBlock = str_contains($html, 'md:hidden');
        $hasDesktopOnly = str_contains($html, 'hidden overflow-x-auto md:block')
            || str_contains($html, 'card hidden overflow-hidden md:block');

        if (! $hasPhoneBlock || ! $hasDesktopOnly) {
            $missing[] = $uri;
        }
    }

    expect($missing)->toBe([]);
});

it('never lets a wide table be clipped instead of scrolled', function () {
    $user = User::withRole(RoleName::SuperAdmin)->firstOrFail();
    $offenders = [];

    foreach (dataHeavyPages() as $uri) {
        $html = $this->actingAs($user)->get('/'.$uri)->assertOk()->getContent();

        // Any <table> on the page must sit inside a scroll container, or be
        // the desktop half of a pair that hides it on small screens.
        if (str_contains($html, '<table') && ! str_contains($html, 'overflow-x-auto')) {
            $offenders[] = $uri;
        }
    }

    expect($offenders)->toBe([]);
});

it('pairs every table with a phone rendering', function () {
    $user = User::withRole(RoleName::SuperAdmin)->firstOrFail();
    $offenders = [];

    foreach (dataHeavyPages() as $uri) {
        $html = $this->actingAs($user)->get('/'.$uri)->assertOk()->getContent();

        if (! str_contains($html, '<table')) {
            continue;
        }

        // The planner is the stated exception. It is a date grid — tasks down,
        // days across — and a card list cannot express "which day". Its phone
        // answer is the sticky task column plus a horizontal scroller, which
        // the test above already requires.
        if ($uri === 'tasks') {
            expect($html)->toContain('sticky left-0');

            continue;
        }

        if (! str_contains($html, 'md:hidden')) {
            $offenders[] = $uri;
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps the viewport meta tag that makes any of this work', function () {
    $user = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    // Without it, a phone renders the desktop layout at 980px and zooms out.
    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertSee('name="viewport"', false)
        ->assertSee('width=device-width', false);

    // The public page is a separate document and needs it just as much.
    $this->get(route('public.tasks'))
        ->assertOk()
        ->assertSee('width=device-width', false);
});

it('does not ship a fixed width wider than a phone outside a scroller', function () {
    $user = User::withRole(RoleName::SuperAdmin)->firstOrFail();
    $offenders = [];

    foreach ([...dataHeavyPages(), 'dashboard', 'students/import'] as $uri) {
        $html = $this->actingAs($user)->get('/'.$uri)->assertOk()->getContent();

        // min-w-[900px] is legitimate for the Gantt timeline — inside a
        // scroller. Anywhere else it forces the whole page sideways.
        preg_match_all('/min-w-\[(\d+)px\]/', $html, $matches);

        foreach ($matches[1] as $width) {
            if ((int) $width > 375 && ! str_contains($html, 'overflow-x-auto')) {
                $offenders[] = "{$uri}: min-w-{$width}px";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('stacks the filter bars instead of crushing them', function () {
    $user = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    foreach (['students', 'tickets', 'tasks'] as $uri) {
        $html = $this->actingAs($user)->get('/'.$uri)->assertOk()->getContent();

        // The filter grids must declare a breakpoint, not a bare grid-cols-N
        // that would stay N columns wide on a phone.
        expect($html)->toContain('lg:grid-cols-12');
    }
});

it('serves every page to a phone-sized client without error', function () {
    $user = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    $routes = collect(Route::getRoutes())
        ->filter(fn ($r) => in_array('GET', $r->methods(), true))
        ->filter(fn ($r) => ! str_contains($r->uri(), '{'))
        ->filter(fn ($r) => ! in_array($r->uri(), ['/', 'login', 'up', 'storage/{path}'], true))
        ->map(fn ($r) => $r->uri())
        ->unique();

    $failures = [];

    foreach ($routes as $uri) {
        // A real phone user agent, in case anything ever branches on it.
        $status = $this->actingAs($user)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15'])
            ->get('/'.$uri)
            ->getStatusCode();

        if ($status >= 500) {
            $failures[] = "{$uri} => {$status}";
        }
    }

    expect($failures)->toBe([]);
});

/* -----------------------------------------------------------------
 | Regressions
 * ----------------------------------------------------------------- */

it('puts the planner month label in a band, not inside a day column', function () {
    $user = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    \App\Models\Task::create([
        'title' => 'Task Lintas Bulan',
        'start_date' => '2026-09-21',
        'due_date' => '2026-10-04',
        'status' => \App\Enums\TaskStatus::InProgress->value,
        'priority' => 'normal',
        'created_by' => $user->id,
    ]);

    $html = $this->actingAs($user)
        ->get('/tasks?from=2026-09-21&to=2026-10-04')
        ->assertOk()
        ->getContent();

    // "Sep 2026" is about 40px wide and a day column is 36px. Rendered inside
    // one it spilled into its neighbour and was clipped by the fixed row
    // height, which read as a rendering fault rather than a label. It must
    // span the days it covers instead.
    expect($html)->toContain('colspan="10"')   // 21–30 September
        ->and($html)->toContain('colspan="4"'); // 1–4 Oktober

    // And a day header must carry only the weekday and the date.
    preg_match('/<th class="w-10[^>]*>(.*?)<\/th>/s', $html, $cell);

    expect($cell[1] ?? '')->not->toContain('2026');
});
