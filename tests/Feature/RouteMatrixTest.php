<?php
use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;

uses(DatabaseTransactions::class);

/** Every GET route must answer 200/302/403 for every role — never 500. */
it('serves every GET route without server errors for all roles', function () {
    $routes = collect(Route::getRoutes())
        ->filter(fn ($r) => in_array('GET', $r->methods(), true))
        ->filter(fn ($r) => ! str_contains($r->uri(), '{'))     // skip parameterised
        ->filter(fn ($r) => ! in_array($r->uri(), ['/', 'login', 'up', 'storage/{path}'], true))
        ->map(fn ($r) => $r->uri())
        ->unique()
        ->values();

    expect($routes)->not->toBeEmpty();

    $failures = [];

    foreach (RoleName::cases() as $roleName) {
        $user = User::withRole($roleName)->first();
        if (! $user) continue;

        foreach ($routes as $uri) {
            $status = $this->actingAs($user)->get('/'.$uri)->getStatusCode();

            if ($status >= 500) {
                $failures[] = "{$roleName->value} GET /{$uri} => {$status}";
            }
        }
    }

    expect($failures)->toBe([]);
});
