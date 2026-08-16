<?php

use App\Models\EnrollmentToken;
use App\Models\Organization;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Regression: named rate limiters must be registered
|--------------------------------------------------------------------------
| In production the deployed AppServiceProvider::boot() was empty, so the
| `enrollment` (and `login`) named limiters were never registered. Any request
| routed through `throttle:enrollment` then threw
| Illuminate\Cache\RateLimiting\MissingRateLimiterException and returned a 500,
| breaking every plugin enrollment. These tests prove the limiters resolve and
| that the throttled route no longer 500s because of a missing limiter.
*/

test('enrollment named rate limiter is registered', function () {
    expect(RateLimiter::limiter('enrollment'))->not->toBeNull();
});

test('login named rate limiter is registered', function () {
    expect(RateLimiter::limiter('login'))->not->toBeNull();
});

test('throttle:enrollment route does not throw MissingRateLimiterException', function () {
    $org = Organization::factory()->create();

    $rawToken = 'MQ-CONNECT-' . strtoupper(Str::random(16));
    EnrollmentToken::create([
        'organization_id' => $org->id,
        'token_hash' => hash('sha256', $rawToken),
        'expires_at' => now()->addMinutes(30),
    ]);

    $response = $this->postJson('/api/v1/enrollment', [
        'token' => $rawToken,
        'domain' => 'example.com',
        'home_url' => 'https://example.com',
        'site_url' => 'https://example.com',
        'plugin_version' => '1.1.1',
    ]);

    // The limiter resolved: we get a normal 201, never a 500 from the missing
    // limiter exception.
    expect($response->status())->toBe(201);
    expect($response->status())->not->toBe(500);
});

test('enrollment endpoint returns 429 once the per-minute limit is exceeded', function () {
    $limit = (int) config('marqira.enrollment_token.rate_limit_per_minute', 10);

    // Fire limit+1 requests from the same IP. Invalid tokens still count against
    // the throttle, so we do not need a valid token here.
    $lastStatus = null;
    for ($i = 0; $i < $limit + 1; $i++) {
        $response = $this->postJson('/api/v1/enrollment', [
            'token' => 'MQ-CONNECT-INVALIDTOKEN',
            'domain' => 'example.com',
            'home_url' => 'https://example.com',
            'site_url' => 'https://example.com',
            'plugin_version' => '1.1.1',
        ]);
        $lastStatus = $response->status();
    }

    expect($lastStatus)->toBe(429);
});
