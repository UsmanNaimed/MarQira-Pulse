<?php

namespace App\Providers;

use App\Models\Site;
use App\Policies\SitePolicy;
use App\Services\Encryption\SecretEncryptor;
use App\Services\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // TenantContext holds per-request tenant state and fails closed.
        $this->app->singleton(TenantContext::class);

        // SecretEncryptor is stateless once constructed with the configured key.
        $this->app->singleton(SecretEncryptor::class, function () {
            return new SecretEncryptor(config('marqira.secret_key'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Server-side authorization for site actions (Owner vs Subscriber).
        Gate::policy(Site::class, SitePolicy::class);

        // Rate limiter for the public enrollment endpoint (keyed by client IP).
        RateLimiter::for('enrollment', function (Request $request) {
            $perMinute = (int) config('marqira.enrollment_token.rate_limit_per_minute', 10);

            return Limit::perMinute($perMinute)->by($request->ip());
        });

        // Rate limiter for the dashboard login endpoint (keyed by email + IP).
        RateLimiter::for('login', function (Request $request) {
            $key = mb_strtolower((string) $request->input('email')).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });
    }
}
