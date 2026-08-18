<?php

namespace App\Services\Monitoring;

use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Performs an INDEPENDENT active uptime probe of a website over HTTP(S).
 *
 * This is the second, independent evidence source that breaks the false-offline
 * problem: heartbeats prove the connector is talking to us, but their absence is
 * ambiguous (idle site, disabled cron, connector/firewall/worker problems). An
 * active probe answers the only question that matters to the user — "does the
 * website actually respond when opened?" — from the monitoring server itself.
 *
 * Design notes:
 *  - Uses Laravel's HTTP client (Guzzle/cURL). No new dependency.
 *  - Follows redirects (a 301/302 to the canonical URL is a healthy site).
 *  - A generous timeout tolerates free-tier cold starts.
 *  - In-probe retries absorb a single transient blip and give a cold-starting
 *    host a second chance within the same run.
 *  - A successful attempt short-circuits immediately (no wasted requests).
 *  - Classification is deliberately conservative: any HTTP response at all means
 *    the server is reachable; only 5xx and transport failures (DNS/TCP/TLS/
 *    timeout) count as DOWN, and even then only as a CANDIDATE that the caller
 *    must confirm across multiple runs before alerting.
 */
class SiteHealthChecker
{
    /**
     * Probe a site and return a structured verdict. Never throws.
     */
    public function check(Site $site): HealthCheckResult
    {
        $url = $this->resolveUrl($site);

        if ($url === null) {
            return HealthCheckResult::inconclusive(
                HealthCheckResult::CAT_NO_URL,
                'Site has no probeable home_url, site_url or domain.'
            );
        }

        $config = (array) config('marqira.heartbeat.active_check', []);
        $attempts = max(1, ((int) ($config['retries'] ?? 1)) + 1);
        $backoffMs = max(0, (int) ($config['retry_backoff_ms'] ?? 750));

        $result = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $result = $this->attempt($url, $config);

            // A confirmed UP result is decisive — stop probing.
            if ($result->isUp()) {
                return $result;
            }

            // An INCONCLUSIVE result (no URL / local error) will not improve on
            // retry; return it as-is.
            if ($result->isInconclusive()) {
                return $result;
            }

            // DOWN: give a transient failure / cold start one more chance.
            if ($attempt < $attempts && $backoffMs > 0) {
                usleep($backoffMs * 1000);
            }
        }

        return $result;
    }

    /**
     * Resolve the best URL to probe: prefer the canonical home URL, then the
     * WordPress site URL, then a bare https:// domain. Ensures a scheme so the
     * HTTP client does not reject a bare host.
     */
    public function resolveUrl(Site $site): ?string
    {
        foreach ([$site->home_url, $site->site_url] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $this->ensureScheme(trim($candidate));
            }
        }

        if (is_string($site->domain) && trim($site->domain) !== '') {
            return $this->ensureScheme(trim($site->domain));
        }

        return null;
    }

    private function ensureScheme(string $url): string
    {
        if (! preg_match('#^https?://#i', $url)) {
            return 'https://' . ltrim($url, '/');
        }

        return $url;
    }

    /**
     * Run a single probe attempt and classify the outcome.
     */
    private function attempt(string $url, array $config): HealthCheckResult
    {
        $timeout = max(1, (int) ($config['timeout_seconds'] ?? 15));
        $connectTimeout = max(1, (int) ($config['connect_timeout_seconds'] ?? 10));
        $userAgent = (string) ($config['user_agent'] ?? 'MarQira-Pulse-Monitor/1.0');

        $start = microtime(true);

        try {
            $response = Http::withHeaders([
                'User-Agent' => $userAgent,
                // Ask for a lightweight response; many hosts honor this.
                'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8',
            ])
                ->timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->withOptions([
                    'allow_redirects' => [
                        'max' => 5,
                        'strict' => false,
                        'referer' => false,
                        'protocols' => ['http', 'https'],
                        'track_redirects' => false,
                    ],
                    // Verify TLS: a broken certificate is a real, visitor-facing
                    // problem and should surface as a TLS failure below.
                    'verify' => true,
                    'http_errors' => false,
                ])
                ->get($url);

            $latencyMs = (int) round((microtime(true) - $start) * 1000);

            return $this->classifyStatus($response->status(), $latencyMs, $url);
        } catch (ConnectionException $e) {
            $latencyMs = (int) round((microtime(true) - $start) * 1000);

            return $this->classifyTransportError($e->getMessage(), $latencyMs, $url);
        } catch (\Throwable $e) {
            // Unexpected local error — do not let it masquerade as a site outage.
            return HealthCheckResult::inconclusive(
                HealthCheckResult::CAT_PROBE_ERROR,
                $e->getMessage(),
                $url
            );
        }
    }

    /**
     * Classify an HTTP status code.
     *
     *  - 2xx / 3xx                -> UP (reachable, serving/redirecting).
     *  - 4xx (incl. 401/403/429)  -> UP (server is clearly responding; it is
     *                                just refusing/rate-limiting THIS request —
     *                                not a site outage).
     *  - 5xx                      -> DOWN candidate (server reachable but erroring).
     */
    private function classifyStatus(int $code, int $latencyMs, string $url): HealthCheckResult
    {
        if ($code >= 200 && $code < 400) {
            return HealthCheckResult::up(HealthCheckResult::CAT_OK, $code, $latencyMs, $url);
        }

        if ($code >= 400 && $code < 500) {
            return HealthCheckResult::up(HealthCheckResult::CAT_HTTP_4XX, $code, $latencyMs, $url);
        }

        // 5xx and anything unexpected (>=600) — treat as a down candidate.
        return HealthCheckResult::down(HealthCheckResult::CAT_HTTP_5XX, $code, $latencyMs, $url);
    }

    /**
     * Classify a transport-level failure by inspecting the cURL/Guzzle message.
     * Categorization drives logging and the batch worker-network guard.
     */
    private function classifyTransportError(string $message, int $latencyMs, string $url): HealthCheckResult
    {
        $m = strtolower($message);

        // TLS / SSL problems (cURL 35, 51, 58, 60, 66, 77, 83…).
        if (str_contains($m, 'ssl') || str_contains($m, 'tls')
            || str_contains($m, 'certificate') || str_contains($m, 'cert ')) {
            return HealthCheckResult::down(HealthCheckResult::CAT_TLS, null, $latencyMs, $url, $message);
        }

        // DNS resolution failures (cURL 6/7 "could not resolve host").
        if (str_contains($m, 'could not resolve') || str_contains($m, 'resolve host')
            || str_contains($m, 'name or service not known') || str_contains($m, 'name resolution')) {
            return HealthCheckResult::down(HealthCheckResult::CAT_DNS, null, $latencyMs, $url, $message);
        }

        // Timeouts (cURL 28).
        if (str_contains($m, 'timed out') || str_contains($m, 'timeout')
            || str_contains($m, 'operation too slow')) {
            return HealthCheckResult::down(HealthCheckResult::CAT_TIMEOUT, null, $latencyMs, $url, $message);
        }

        // Remaining connection failures: refused, reset, network unreachable…
        return HealthCheckResult::down(HealthCheckResult::CAT_CONNECTION, null, $latencyMs, $url, $message);
    }
}
