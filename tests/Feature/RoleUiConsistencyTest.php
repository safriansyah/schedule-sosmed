<?php

/**
 * What a role is SHOWN must match what a role may DO.
 *
 * Two promises, checked for every role on every page it can open:
 *
 *  1. Every form it sees with a working submit button, it may submit. A form
 *     that renders and then answers 403 is a lie in the interface — the
 *     "editable student record a Follow Up operator could not save" kind of
 *     bug. (A form whose fields are all disabled and that has no submit
 *     button is read-only on purpose, and is skipped.)
 *  2. Every link in its sidebar opens.
 *
 * Forms are submitted EMPTY, so the answer is 403 (not allowed) or something
 * else (allowed — usually a validation error). Everything runs inside the
 * test's transaction with external services faked: nothing it does survives.
 */

use App\Enums\GuestBookStatus;
use App\Enums\RoleName;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Models\GuestBookEntry;
use App\Models\Student;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

/** Routes never submitted or opened by the sweep. */
const SWEEP_SKIP_ROUTES = [
    'logout',                 // would end the session mid-sweep
    'login',
];

/** GET pages that are not HTML pages (downloads, feeds, images, JSON). */
function sweepIsPage(\Illuminate\Routing\Route $route): bool
{
    $name = (string) $route->getName();
    $uri = $route->uri();

    return ! preg_match('/(export|template|feed|status$|rows$|paraf|errors$|events$|kecamatan$|table$|account$|^sync|^up$|^storage|^_|selesai$)/', $uri)
        && ! in_array($name, SWEEP_SKIP_ROUTES, true)
        && ! str_starts_with($uri, 'guest-book');
}

/**
 * Records the role can see, so the DETAIL pages (where the edit forms live)
 * are opened too.
 *
 * @return array<string, string|int>
 */
function sweepFixtures(User $user): array
{
    $admin = User::withRole(RoleName::SuperAdmin)->firstOrFail();

    $student = Student::create([
        'nim' => 'SWEEP'.substr(uniqid(), -5), 'nama' => 'Mahasiswa Sapuan',
        'kabupaten' => 'Kab. Sapuan', 'assigned_to' => $user->id, 'assignment_status' => 'assigned',
    ]);

    $ticket = Ticket::createWithNumber([
        'subject' => 'Tiket sapuan', 'source' => TicketSource::StudentImport, 'status' => TicketStatus::Assigned,
        'student_id' => $student->id, 'assigned_to' => $user->id, 'created_by' => $admin->id,
    ]);

    $task = Task::query()->first() ?? Task::create([
        'title' => 'Task sapuan', 'start_date' => now()->toDateString(), 'due_date' => now()->addDay()->toDateString(),
        'status' => 'planned', 'progress' => 0, 'created_by' => $admin->id, 'pic_id' => $user->id,
    ]);

    $entry = GuestBookEntry::take([
        'whatsapp' => '6281234567890', 'phone' => '6281234567890', 'name' => 'Tamu Sapuan', 'nim' => null,
        'gender' => 'perempuan', 'service' => 'legalisir_ijazah', 'description' => null,
        'signature_path' => null, 'ip_address' => '127.0.0.1',
    ]);
    $entry->forceFill(['status' => GuestBookStatus::Waiting])->save();

    // Modules that may well be empty in the database: without a record their
    // detail pages — and every form on them — would be skipped silently.
    $dataset = \App\Models\Dataset::query()->first() ?? tap(new \App\Models\Dataset, function ($d) use ($admin) {
        $d->forceFill(['name' => 'Dataset sapuan', 'slug' => 'dataset-sapuan-'.uniqid(), 'status' => 'completed', 'created_by' => $admin->id])->save();
    });
    \App\Models\DatasetItem::query()->where('dataset_id', $dataset->id)->exists()
        || \App\Models\DatasetItem::query()->forceCreate(['dataset_id' => $dataset->id, 'name' => 'Baris sapuan', 'username' => 'sapuan', 'platform' => 'instagram']);

    $content = \App\Models\Content::query()->first() ?? tap(new \App\Models\Content, function ($c) use ($user) {
        $c->forceFill(['title' => 'Konten sapuan', 'status' => 'draft', 'created_by' => $user->id])->save();
    });

    return array_filter([
        'dataset' => $dataset->id,
        'content' => $content->id,
        'ticket' => $ticket->id,
        'student' => $student->id,
        'task' => $task->id,
        'entry' => $entry->id,
        'interaction' => \App\Models\Interaction::query()->value('id'),
        'contact' => \App\Models\Contact::query()->value('id'),
        'media' => \App\Models\AccountMedia::query()->value('id'),
        'account' => \App\Models\SocialAccount::query()->value('id'),
        'import' => \App\Models\StudentImport::query()->value('id'),
        'user' => User::where('id', '!=', $user->id)->value('id'),
        'username' => \App\Models\Interaction::whereNotNull('author_handle')->value('author_handle'),
    ], fn ($v) => $v !== null);
}

/**
 * Forms on a page that a person could actually submit: POST, with an action,
 * and at least one enabled submit control outside a disabled fieldset.
 *
 * @return array<int, array{method: string, path: string}>
 */
function sweepSubmittableForms(string $html): array
{
    $doc = new DOMDocument;
    @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($doc);
    $forms = [];

    foreach ($xpath->query('//form') as $form) {
        if (strtolower($form->getAttribute('method')) !== 'post' || $form->getAttribute('action') === '') {
            continue;
        }

        $submit = $xpath->query(
            './/button[not(@type="button") and not(@disabled) and not(ancestor::fieldset[@disabled])]'
            .' | .//input[@type="submit" and not(@disabled)]',
            $form,
        );

        if ($submit->length === 0) {
            continue;
        }

        $override = $xpath->query('.//input[@name="_method"]', $form)->item(0);
        $path = parse_url($form->getAttribute('action'), PHP_URL_PATH) ?: '/';

        $forms[] = [
            'method' => strtoupper($override?->getAttribute('value') ?: 'POST'),
            'path' => $path,
        ];
    }

    return $forms;
}

/** @return array<int, string> internal sidebar links */
function sweepSidebarLinks(string $html): array
{
    $doc = new DOMDocument;
    @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $links = [];

    foreach ((new DOMXPath($doc))->query('//aside//a[@href]') as $a) {
        $href = $a->getAttribute('href');
        $path = parse_url($href, PHP_URL_PATH);
        $host = parse_url($href, PHP_URL_HOST);

        if ($path && (! $host || $host === parse_url(config('app.url'), PHP_URL_HOST) || $host === 'localhost')) {
            $query = parse_url($href, PHP_URL_QUERY);
            $links[] = $path.($query ? '?'.$query : '');
        }
    }

    return array_values(array_unique($links));
}

dataset('roles', fn () => array_map(fn (RoleName $r) => [$r], RoleName::cases()));

beforeEach(function () {
    Http::fake();
    Queue::fake();
    Notification::fake();
    Storage::fake('local');
    Storage::fake('public');
});

it('only shows a role forms it may submit, and sidebar links it may open', function (RoleName $role) {
    $user = User::withRole($role)->firstOrFail();
    $params = sweepFixtures($user);
    $violations = [];
    $checkedForms = [];

    $this->actingAs($user);

    // 2. Sidebar links.
    $dashboard = $this->get(route('dashboard'));
    if ($dashboard->status() === 200) {
        foreach (sweepSidebarLinks($dashboard->getContent()) as $link) {
            $status = $this->get($link)->status();
            if ($status === 403) {
                $violations[] = "sidebar: {$link} → 403";
            }
        }
    }

    // 1. Every page it can open, and every submittable form on it.
    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true) || ! sweepIsPage($route)) {
            continue;
        }

        $uri = $route->uri();
        $missing = false;
        $path = preg_replace_callback('/\{(\w+)\??\}/', function ($m) use ($params, &$missing) {
            if (! isset($params[$m[1]])) {
                $missing = true;

                return '';
            }

            return (string) $params[$m[1]];
        }, $uri);

        if ($missing) {
            continue;
        }

        $response = $this->get('/'.ltrim($path, '/'));

        if ($response->status() !== 200 || ! str_contains((string) $response->headers->get('content-type'), 'html')) {
            continue;
        }

        foreach (sweepSubmittableForms($response->getContent()) as $form) {
            $key = $form['method'].' '.$form['path'];

            if (isset($checkedForms[$key]) || $form['path'] === '/logout') {
                continue;
            }
            // An earlier form on this page may have changed the record (say,
            // "Ajukan" moved a draft on, so "Hapus" is rightly gone). Judge
            // each form against the page as it is NOW, not as first loaded.
            $now = $this->get('/'.ltrim($path, '/'));
            $stillShown = $now->status() === 200 && collect(sweepSubmittableForms($now->getContent()))
                ->contains(fn ($f) => $f['method'] === $form['method'] && $f['path'] === $form['path']);

            if (! $stillShown) {
                continue;
            }

            $checkedForms[$key] = true;

            $status = $this->call($form['method'], $form['path'], [], [], [], ['HTTP_ACCEPT' => 'text/html', 'HTTP_REFERER' => url($path)])->status();

            if ($status === 403) {
                $violations[] = "/{$path}: form {$key} → 403";
            }

            // An action may have logged the user out or changed their role
            // in this transaction; keep sweeping as the same person.
            $this->actingAs($user->fresh() ?? $user);
        }
    }

    expect($violations)->toBe([], "Role {$role->value} melihat aksi yang tidak boleh dipakainya:\n".implode("\n", $violations));
})->with('roles');
