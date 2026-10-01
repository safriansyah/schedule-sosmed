<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Runs on every web request so a deactivated account loses access at once.
        $middleware->web(append: [
            \App\Http\Middleware\EnsureUserIsActive::class,
        ]);

        // Behind a Cloudflare Tunnel or ngrok, TLS is terminated by the tunnel
        // and the request reaches PHP over plain HTTP from the local agent.
        // Without this Laravel believes the request is insecure: every
        // generated URL comes out http://, which the browser then blocks as
        // mixed content, and the media URL handed to Instagram is wrong.
        //
        // The agent runs on this machine and forwards to 127.0.0.1, so trusting
        // loopback is enough — no need to trust arbitrary upstream proxies.
        // TRUSTED_PROXIES widens it if the app ever sits behind a real one.
        $middleware->trustProxies(
            at: array_filter(explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1'))),
            headers: Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                | Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
                | Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                | Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO
                | Illuminate\Http\Request::HEADER_X_FORWARDED_AWS_ELB,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
