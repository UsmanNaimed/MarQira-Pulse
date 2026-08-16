<?php

namespace App\Providers;

use App\Services\Encryption\SecretEncryptor;
use App\Services\TenantContext;
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
        //
    }
}
