<?php

namespace App\Services\Monitoring;

/**
 * Immutable outcome of a single active health probe against a website.
 *
 * A probe resolves to exactly one of three verdicts:
 *
 *   - UP           The website answered in a way that proves it is reachable and
 *                  serving (any 2xx/3xx, and also 4xx such as 401/403/404/429 —
 *                  the server is clearly up, it is just refusing/redirecting the
 *                  specific request). A quiet site that has stopped heart-beating
 *                  but still answers here is NOT an outage.
 *
 *   - DOWN         The website could not be reached or is broken in a way a
 *                  visitor would experience as an outage: DNS failure, TCP
 *                  connection refused/timeout, TLS handshake failure, or a 5xx
 *                  server error. DOWN is a CANDIDATE signal only — the monitor
 *                  requires several consecutive DOWN probes before alerting.
 *
 *   - INCONCLUSIVE The probe itself could not be trusted (no probeable URL, or an
 *                  unexpected local error). INCONCLUSIVE never changes a site's
 *                  online/offline state.
 */
final class HealthCheckResult
{
    public const UP = 'up';
    public const DOWN = 'down';
    public const INCONCLUSIVE = 'inconclusive';

    /** Failure categories used for logging and the batch worker-network guard. */
    public const CAT_OK = 'ok';
    public const CAT_HTTP_4XX = 'http_4xx';
    public const CAT_HTTP_5XX = 'http_5xx';
    public const CAT_DNS = 'dns';
    public const CAT_CONNECTION = 'connection';
    public const CAT_TIMEOUT = 'timeout';
    public const CAT_TLS = 'tls';
    public const CAT_NO_URL = 'no_url';
    public const CAT_PROBE_ERROR = 'probe_error';

    /**
     * Network-level failure categories that point at connectivity rather than a
     * specific site being broken. A whole run dominated by these is treated as a
     * monitoring-side problem (see CheckStaleSitesCommand batch guard).
     */
    public const NETWORK_CATEGORIES = [
        self::CAT_DNS,
        self::CAT_CONNECTION,
        self::CAT_TIMEOUT,
    ];

    private function __construct(
        public readonly string $status,
        public readonly string $category,
        public readonly ?int $httpCode,
        public readonly ?int $latencyMs,
        public readonly ?string $url,
        public readonly ?string $detail,
    ) {
    }

    public static function up(string $category, ?int $httpCode, ?int $latencyMs, ?string $url = null, ?string $detail = null): self
    {
        return new self(self::UP, $category, $httpCode, $latencyMs, $url, $detail);
    }

    public static function down(string $category, ?int $httpCode, ?int $latencyMs, ?string $url = null, ?string $detail = null): self
    {
        return new self(self::DOWN, $category, $httpCode, $latencyMs, $url, $detail);
    }

    public static function inconclusive(string $category, ?string $detail = null, ?string $url = null): self
    {
        return new self(self::INCONCLUSIVE, $category, null, null, $url, $detail);
    }

    public function isUp(): bool
    {
        return $this->status === self::UP;
    }

    public function isDown(): bool
    {
        return $this->status === self::DOWN;
    }

    public function isInconclusive(): bool
    {
        return $this->status === self::INCONCLUSIVE;
    }

    /**
     * Whether this DOWN result is a network-level failure (DNS/connect/timeout),
     * used by the batch guard to detect monitoring-side connectivity problems.
     */
    public function isNetworkFailure(): bool
    {
        return $this->isDown() && in_array($this->category, self::NETWORK_CATEGORIES, true);
    }

    /**
     * A compact machine-readable reason string for persistence/audit, e.g.
     * "http_5xx:503" or "timeout" or "ok:200".
     */
    public function reason(): string
    {
        if ($this->httpCode !== null) {
            return $this->category . ':' . $this->httpCode;
        }

        return $this->category;
    }
}
