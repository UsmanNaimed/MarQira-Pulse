<?php

use App\Models\Site;
use App\Services\Monitoring\HealthCheckResult;
use App\Services\Monitoring\SiteHealthChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Unit coverage for the active probe's response classification. These prove the
 * exact rules the false-offline fix relies on: what counts as "up" vs "down" vs
 * "inconclusive", and how transport errors are categorised for the batch guard.
 */

beforeEach(function () {
    config([
        'marqira.heartbeat.active_check.retries' => 0,
        'marqira.heartbeat.active_check.retry_backoff_ms' => 0,
        'marqira.heartbeat.active_check.timeout_seconds' => 5,
        'marqira.heartbeat.active_check.connect_timeout_seconds' => 3,
    ]);
});

function probeSite(): Site
{
    return new Site(['domain' => 'example.test', 'home_url' => 'https://example.test']);
}

function runCheck(): HealthCheckResult
{
    return app(SiteHealthChecker::class)->check(probeSite());
}

test('a 200 response is UP', function () {
    Http::fake(['*' => Http::response('OK', 200)]);
    $r = runCheck();
    expect($r->isUp())->toBeTrue();
    expect($r->category)->toBe(HealthCheckResult::CAT_OK);
    expect($r->httpCode)->toBe(200);
});

test('a 301 redirect that resolves to 200 is UP', function () {
    // Initial URL 301-redirects to /home, which serves 200. The probe follows
    // the redirect and classifies the final 200 as UP.
    Http::fake([
        '*/home' => Http::response('OK', 200),
        '*' => Http::response('', 301, ['Location' => 'https://example.test/home']),
    ]);
    expect(runCheck()->isUp())->toBeTrue();
});

test('a 403 response is UP (server is responding, just refusing this request)', function () {
    Http::fake(['*' => Http::response('Forbidden', 403)]);
    $r = runCheck();
    expect($r->isUp())->toBeTrue();
    expect($r->category)->toBe(HealthCheckResult::CAT_HTTP_4XX);
});

test('a 404 response is UP (reachable)', function () {
    Http::fake(['*' => Http::response('Not found', 404)]);
    expect(runCheck()->isUp())->toBeTrue();
});

test('a 429 rate-limit response is UP (not an outage)', function () {
    Http::fake(['*' => Http::response('Too Many Requests', 429)]);
    expect(runCheck()->isUp())->toBeTrue();
});

test('a 503 response is DOWN (server error)', function () {
    Http::fake(['*' => Http::response('Service Unavailable', 503)]);
    $r = runCheck();
    expect($r->isDown())->toBeTrue();
    expect($r->category)->toBe(HealthCheckResult::CAT_HTTP_5XX);
    expect($r->isNetworkFailure())->toBeFalse();
});

test('a DNS failure is DOWN and flagged as a network failure', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: example.test'));
    $r = runCheck();
    expect($r->isDown())->toBeTrue();
    expect($r->category)->toBe(HealthCheckResult::CAT_DNS);
    expect($r->isNetworkFailure())->toBeTrue();
});

test('a connection refused is DOWN and flagged as a network failure', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to example.test port 443: Connection refused'));
    $r = runCheck();
    expect($r->isDown())->toBeTrue();
    expect($r->category)->toBe(HealthCheckResult::CAT_CONNECTION);
    expect($r->isNetworkFailure())->toBeTrue();
});

test('a timeout is DOWN and flagged as a network failure', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 15000 milliseconds'));
    $r = runCheck();
    expect($r->isDown())->toBeTrue();
    expect($r->category)->toBe(HealthCheckResult::CAT_TIMEOUT);
    expect($r->isNetworkFailure())->toBeTrue();
});

test('a TLS/SSL failure is DOWN but NOT a network failure (site-specific)', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 60: SSL certificate problem: certificate has expired'));
    $r = runCheck();
    expect($r->isDown())->toBeTrue();
    expect($r->category)->toBe(HealthCheckResult::CAT_TLS);
    expect($r->isNetworkFailure())->toBeFalse();
});

test('a site with no probeable URL is INCONCLUSIVE, never down', function () {
    Http::fake();
    $r = app(SiteHealthChecker::class)->check(new Site([]));
    expect($r->isInconclusive())->toBeTrue();
    expect($r->category)->toBe(HealthCheckResult::CAT_NO_URL);
});

test('an in-probe retry lets a cold-starting site pass (503 then 200)', function () {
    config(['marqira.heartbeat.active_check.retries' => 1]);
    Http::fakeSequence()
        ->push('Service Unavailable', 503)
        ->push('OK', 200);
    expect(runCheck()->isUp())->toBeTrue();
});

test('resolveUrl prefers home_url, falls back to site_url then domain', function () {
    $checker = app(SiteHealthChecker::class);
    expect($checker->resolveUrl(new Site(['home_url' => 'https://a.test', 'site_url' => 'https://b.test', 'domain' => 'c.test'])))->toBe('https://a.test');
    expect($checker->resolveUrl(new Site(['site_url' => 'https://b.test', 'domain' => 'c.test'])))->toBe('https://b.test');
    expect($checker->resolveUrl(new Site(['domain' => 'c.test'])))->toBe('https://c.test');
    expect($checker->resolveUrl(new Site([])))->toBeNull();
});
