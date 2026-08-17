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

        // Enable Sanctum stateful (cookie/session) authentication for the SPA
        // dashboard. This runs the EnsureFrontendRequestsAreStateful middleware
        // for API routes so requests from SANCTUM_STATEFUL_DOMAINS use the web
        // session guard + CSRF protection instead of a bearer token.
        $middleware->statefulApi();

        // Register middleware aliases.
        $middleware->alias([
            'hmac.auth' => \App\Http\Middleware\HmacAuthentication::class,
            'tenant' => \App\Http\Middleware\SetTenantFromUser::class,
            'owner' => \App\Http\Middleware\EnsureOwner::class,
        ]);

        // Named rate limiters ("enrollment", "login") are defined in
        // App\Providers\AppServiceProvider::boot().
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
