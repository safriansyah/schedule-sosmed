<?php

/**
 * Every page, as every role: nothing may answer with a server error.
 *
 * Walks all named GET routes. A route with parameters is filled from the
 * first record the role could plausibly open; a route whose parameters
 * cannot be filled is skipped rather than guessed. 403/404/302 are fine —
 * whether a role SHOULD see a page is covered by the role tests — but a 500
 * is a bug whoever hits it.
 */

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

uses(DatabaseTransactions::class);

/** Route parameters that are not models, with a value that exists. */
function crawlParameterValue(string $name): mixed
{
    $models = [
        'ticket' => \App\Models\Ticket::class,
        'student' => \App\Models\Student::class,
        'import' => null, // ambiguous: students vs leads, resolved per route
        'entry' => \App\Models\GuestBookEntry::class,
        'content' => \App\Models\Content::class,
        'user' => \App\Models\User::class,
        'task' => \App\Models\Task::class,
        'interaction' => \App\Models\Interaction::class,
        'contact' => \App\Models\Contact::class,
        'account' => \App\Models\SocialAccount::class,
        'dataset' => \App\Models\Dataset::class,
        'category' => \App\Models\TicketCategory::class,
        'media' => \App\Models\AccountMedia::class,
    ];

    if ($name === 'username') {
        return \App\Models\SocialAccount::query()->whereNotNull('username')->value('username');
    }

    $class = $models[$name] ?? null;

    return $class ? $class::query()->value('id') : null;
}

it('never answers a page with a server error, whoever asks', function () {
    // Records to open, inside the test's transaction.
    $this->post(route('guest-book.store'), [
        'whatsapp' => '081290009999', 'phone' => '081290009998', 'name' => 'Tamu Crawl',
        'gender' => 'laki_laki', 'service' => \App\Enums\GuestBookService::cases()[0]->value,
        'description' => 'Uji', 'signature' => 'data:image/png;base64,'.base64_encode((function () {
            $im = imagecreatetruecolor(20, 10);
            ob_start();
            imagepng($im);

            return ob_get_clean();
        })()),
    ]);

    // A ticket, too: the live data may have none.
    $entry = \App\Models\GuestBookEntry::latest('id')->first();
    $this->actingAs(User::withRole(RoleName::Manager)->firstOrFail())
        ->postJson(route('guest-book.admin.ticket', $entry), [])->assertOk();
    app('auth')->forgetGuards();

    // And a content, a task and a lead import.
    $manager = User::withRole(RoleName::Manager)->firstOrFail();
    \App\Models\Content::forceCreate(['title' => 'Konten Crawl', 'created_by' => User::withRole(RoleName::Creative)->value('id')]);
    \App\Models\Task::forceCreate(['title' => 'Task Crawl', 'start_date' => today(), 'due_date' => today()->addDay(), 'created_by' => $manager->id]);
    $this->actingAs($manager)->post(route('leads.import.store'), [
        'source' => 'mitra',
        'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('l.csv', "Nama,No HP,Kota
Crawl,081290009997,Bangka
"),
    ]);
    app('auth')->forgetGuards();

    $skip = '/(logout|export|download|template|errors|paraf|signature|sse|stream|feed|callback|oauth|webhook|redirect|media\/|image|proxy)/i';

    $routes = collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $r) => in_array('GET', $r->methods(), true)
            && $r->getName()
            && in_array('auth', $r->gatherMiddleware(), true)
            && ! preg_match($skip, $r->uri()));

    $failures = [];
    $skipped = [];

    foreach (RoleName::cases() as $role) {
        $user = User::withRole($role)->first();

        if (! $user) {
            continue;
        }

        foreach ($routes as $route) {
            $params = [];

            foreach ($route->parameterNames() as $name) {
                $value = $name === 'import'
                    ? (str_starts_with($route->getName(), 'leads.') ? \App\Models\LeadImport::value('id') : \App\Models\StudentImport::value('id'))
                    : crawlParameterValue($name);

                if ($value === null) {
                    $skipped[$route->getName()] = true;

                    continue 2;
                }

                $params[$name] = $value;
            }

            $status = $this->actingAs($user)->get(route($route->getName(), $params))->getStatusCode();

            if ($status >= 500) {
                $failures[] = "{$role->value} → {$route->getName()} ".json_encode($params)." = {$status}";
            }

            // Each request is independent.
            app('auth')->forgetGuards();
        }
    }

    expect($failures)->toBe([]);
});
