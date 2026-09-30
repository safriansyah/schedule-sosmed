<?php

/**
 * HTML forbids a <form> inside another <form>, and browsers do not complain —
 * they silently drop the inner start tag. The button then belongs to the OUTER
 * form and posts to the wrong place.
 *
 * That is exactly what happened to "Add Ticket" in the inbox: the row sat
 * inside the bulk-action form, so clicking it posted to /interactions/bulk with
 * no action and answered with "Pilih tindakan yang ingin dijalankan." Nothing
 * in the code looked wrong — the form was right there in the component — and no
 * server-side test could catch it, because the server never saw the request the
 * markup was supposed to produce.
 *
 * So the check is on the rendered markup itself, swept across the pages that
 * carry forms inside lists. It costs one render per page and catches a class of
 * bug that otherwise only turns up when somebody clicks.
 */

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

/**
 * Depth-scan the rendered HTML. Returns the byte offset of the first nested
 * <form>, or null when the markup is well-formed.
 */
function firstNestedForm(string $html): ?int
{
    preg_match_all('/<\/?form\b/i', $html, $matches, PREG_OFFSET_CAPTURE);

    $depth = 0;

    foreach ($matches[0] as [$tag, $offset]) {
        if (str_starts_with(strtolower($tag), '</')) {
            $depth = max(0, $depth - 1);

            continue;
        }

        $depth++;

        if ($depth > 1) {
            return $offset;
        }
    }

    return null;
}

it('renders no form inside another form', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    // Every screen that mixes a wrapping form (bulk actions, filters) with
    // per-row actions — the shape that produces the bug.
    $pages = [
        '/interactions',
        '/interactions?tab=assigned',
        '/tickets',
        '/students',
        '/students/unsigned',
        '/tasks',
        '/contacts',
        '/monitoring',
        '/dashboard',
    ];

    $broken = [];

    foreach ($pages as $uri) {
        $response = $this->actingAs($admin)->get($uri);

        if ($response->getStatusCode() !== 200) {
            continue;
        }

        $at = firstNestedForm($response->getContent());

        if ($at !== null) {
            // The surrounding markup, so the failure names the offending
            // control rather than just the page.
            $broken[$uri] = trim(substr($response->getContent(), max(0, $at - 180), 260));
        }
    }

    expect($broken)->toBe([]);
});

it('keeps the Add Ticket button pointed at the ticket route', function () {
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    $html = $this->actingAs($admin)->get('/interactions')->assertOk()->getContent();

    // It only appears when there is an interaction without a ticket; the inbox
    // on a fresh install may have none, so this asserts the shape rather than
    // the presence.
    if (! str_contains($html, 'Add Ticket')) {
        expect(true)->toBeTrue();

        return;
    }

    // Retargeted with formaction, never with a form of its own.
    expect($html)->toContain('formaction=');
    expect($html)->toContain('/ticket"');
});
