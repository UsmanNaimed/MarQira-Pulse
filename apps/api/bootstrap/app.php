<?php

use App\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Trusted proxies come exclusively from the TRUSTED_PROXIES env var.
        // Never a wildcard — see App\Http\Middleware\TrustProxies.
        $middleware->trustProxies(
            at: TrustProxies::proxies(),
            headers: TrustProxies::HEADERS,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
