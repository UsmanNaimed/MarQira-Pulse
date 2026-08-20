<?php

namespace App\Services\Connector;

use App\Models\Site;
use App\Services\Encryption\SecretEncryptor;
use App\Services\Hmac\HmacService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * API -> site "push" channel.
 *
 * Historically an update command only reached a site on its next WP-Cron
 * heartbeat (every few minutes, and only when the site receives traffic), so a
 * dashboard "update now" click could sit as "pending" for a long time. This
 * client signs the command with the site's HMAC secret and POSTs it straight to
 * the connector's REST endpoint so execution can begin within seconds. The
 * heartbeat path remains the fallback for older connectors and for pushes that
 * cannot be delivered (site unreachable), so delivery is never lost.
 *
 * The signature is bound to a FIXED logical path constant, not the live REST
 * URL, because WordPress serves REST under /wp-json/... or ?rest_route=..., in
 * subdirectories, or behind proxies. Both signer and verifier agree on the
 * stable path so signatures stay portable. See Marqira_Hmac_Server.
 */
class ConnectorClient
{
    /**
     * Stable logical path the execute-update signature covers. MUST match the
     * connector's Marqira_Rest_Controller::EXECUTE_SIGN_PATH exactly.
     */
    public const EXECUTE_SIGN_PATH = '/marqira/v1/execute-update';

    /**
     * REST route the connector registers for the push command.
     */
    private const EXECUTE_ROUTE = 'marqira/v1/execute-update';

    /**
     * How long to wait for the connector to ACCEPT the command (not to run it).
     * The connector queues the job and returns 202 quickly; the actual update
     * runs in a background worker, so this stays short.
     */
    private const CONNECT_TIMEOUT = 8;
    private const REQUEST_TIMEOUT = 15;

    public function __construct(
        private HmacService $hmac,
        private SecretEncryptor $encryptor,
    ) {}

    /**
     * Map the dashboard's internal command type to the connector's verb.
     * Mirrors HeartbeatController::buildPendingUpdateCommand so both channels
     * speak the same protocol.
     */
    public static function verbForType(string $type): string
    {
        return match ($type) {
            Site::UPDATE_CMD_TYPE_CORE    => 'update_core',
            Site::UPDATE_CMD_TYPE_PLUGINS => 'update_all_plugins',
            Site::UPDATE_CMD_TYPE_THEMES  => 'update_all_themes',
            default                       => 'update_plugin', // connector self-update
        };
    }

    /**
     * Push a signed update command to the site and return the outcome.
     *
     * @return array{pushed:bool,state:?string,error:?string,http:?int}
     */
    public function pushUpdateCommand(
        Site $site,
        string $type,
        ?string $targetVersion,
        string $commandId
    ): array {
        $secret = null;
        try {
            $secret = $site->decryptSecret();
        } catch (Throwable $e) {
            return $this->fail('Could not load the site credentials to sign the command.');
        }

        if (! $secret) {
            return $this->fail('This site has no stored credentials; cannot push the command.');
        }

        $base = $this->restBaseUrl($site);
        if (! $base) {
            return $this->fail('This site has no known URL to deliver the command to.');
        }

        $payload = [
            'type'           => self::verbForType($type),
            'target_version' => $targetVersion,
            'command_id'     => $commandId,
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $timestamp = (string) time();
        $nonce = (string) Str::uuid();
        // Prefer the kid the site was enrolled with; fall back to the active key.
        $kid = $site->site_secret_kid ?: $this->encryptor->keyId();

        $canonical = $this->hmac->buildCanonicalData(
            'POST',
            self::EXECUTE_SIGN_PATH,
            [], // query is always empty for signed pushes
            $timestamp,
            $nonce,
            $body
        );
        $signature = $this->hmac->generateSignature($canonical, $secret);

        $headers = [
            'Content-Type'        => 'application/json',
            'Accept'              => 'application/json',
            'X-MarQira-Site'      => $site->uuid,
            'X-MarQira-Timestamp' => $timestamp,
            'X-MarQira-Nonce'     => $nonce,
            'X-MarQira-Kid'       => $kid,
            'X-MarQira-Signature' => $signature,
        ];

        // Primary: pretty permalinks (/wp-json/...). Fallback: plain permalinks
        // (?rest_route=...) which every WordPress install answers regardless of
        // its permalink settings.
        $urls = [
            $base . '/wp-json/' . self::EXECUTE_ROUTE,
            $base . '/?rest_route=/' . self::EXECUTE_ROUTE,
        ];

        $lastError = 'The site could not be reached.';
        $lastHttp = null;

        foreach ($urls as $i => $url) {
            try {
                $response = Http::withHeaders($headers)
                    ->timeout(self::REQUEST_TIMEOUT)
                    ->connectTimeout(self::CONNECT_TIMEOUT)
                    ->withBody($body, 'application/json')
                    ->post($url);
            } catch (Throwable $e) {
                $lastError = 'The site could not be reached: ' . $e->getMessage();
                continue;
            }

            $lastHttp = $response->status();

            // A 404 on the pretty-permalink URL usually just means plain
            // permalinks — try the ?rest_route fallback before giving up.
            if ($response->status() === 404 && $i === 0) {
                $lastError = 'Update endpoint not found at the pretty-permalink URL.';
                continue;
            }

            if ($response->successful()) {
                $json = $response->json();
                $state = is_array($json) ? ($json['state'] ?? Site::UPDATE_CMD_QUEUED) : Site::UPDATE_CMD_QUEUED;

                return [
                    'pushed' => true,
                    'state'  => $state,
                    'error'  => null,
                    'http'   => $response->status(),
                ];
            }

            // Non-2xx: surface a concise reason and stop (auth failures won't
            // change on the fallback URL).
            $json = $response->json();
            $msg = is_array($json) ? ($json['message'] ?? $json['error'] ?? null) : null;
            $lastError = $msg ?: ('The site rejected the command (HTTP ' . $response->status() . ').');

            if (in_array($response->status(), [401, 403], true)) {
                break; // signature/enrollment problem — fallback won't help
            }
        }

        Log::warning('ConnectorClient push failed', [
            'site_uuid'  => $site->uuid,
            'command_id' => $commandId,
            'http'       => $lastHttp,
            'error'      => $lastError,
        ]);

        return $this->fail($lastError, $lastHttp);
    }

    /**
     * Best-effort base URL (scheme://host[/subdir]) for the site's REST API.
     */
    private function restBaseUrl(Site $site): ?string
    {
        $candidate = $site->home_url ?: $site->site_url;

        if (! $candidate && $site->domain) {
            $candidate = 'https://' . $site->domain;
        }

        if (! $candidate) {
            return null;
        }

        return rtrim($candidate, '/');
    }

    /**
     * @return array{pushed:bool,state:?string,error:?string,http:?int}
     */
    private function fail(string $error, ?int $http = null): array
    {
        return [
            'pushed' => false,
            'state'  => null,
            'error'  => $error,
            'http'   => $http,
        ];
    }
}
