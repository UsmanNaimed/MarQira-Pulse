<?php

namespace App\Http\Middleware;

use App\Models\Site;
use App\Services\Encryption\SecretEncryptor;
use App\Services\Hmac\HmacService;
use App\Services\Hmac\NonceManager;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HMAC authentication middleware for WordPress plugin requests.
 *
 * Protocol: HMAC v1
 * Verification order:
 * 1. Validate headers present
 * 2. Validate timestamp (±5 min tolerance)
 * 3. Find site by UUID
 * 4. Establish TenantContext
 * 5. Find key by kid (supports rotation)
 * 6. Verify HMAC signature
 * 7. Atomically claim nonce (replay protection)
 * 8. Proceed
 */
class HmacAuthentication
{
    public function __construct(
        private HmacService $hmacService,
        private NonceManager $nonceManager,
        private SecretEncryptor $secretEncryptor,
        private TenantContext $tenantContext
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Validate required headers are present
        $siteUuid = $request->header('X-MarQira-Site');
        $timestamp = $request->header('X-MarQira-Timestamp');
        $nonce = $request->header('X-MarQira-Nonce');
        $kid = $request->header('X-MarQira-Kid');
        $signature = $request->header('X-MarQira-Signature');

        if (!$siteUuid || !$timestamp || !$nonce || !$kid || !$signature) {
            return response()->json([
                'error' => 'Missing required HMAC headers',
                'required' => [
                    'X-MarQira-Site',
                    'X-MarQira-Timestamp',
                    'X-MarQira-Nonce',
                    'X-MarQira-Kid',
                    'X-MarQira-Signature',
                ],
            ], 400);
        }

        // 2. Validate timestamp (±5 min tolerance)
        if (!$this->hmacService->isTimestampValid($timestamp)) {
            return response()->json([
                'error' => 'Request timestamp expired or invalid',
            ], 401);
        }

        // 3. Find site by UUID
        $site = Site::where('uuid', $siteUuid)->first();

        if (!$site) {
            return response()->json([
                'error' => 'Site not found',
            ], 404);
        }

        // 4. Establish TenantContext (fail-closed guard)
        $this->tenantContext->setOrganization($site->organization);

        // 5. Decrypt site secret
        $siteSecret = $site->decryptSecret();

        if (!$siteSecret) {
            return response()->json([
                'error' => 'Site secret not available',
            ], 500);
        }

        // For key rotation support: check kid matches current key
        // Future: support previous key as well
        $currentKid = $this->secretEncryptor->keyId();
        if ($kid !== $currentKid) {
            // For now, only current key is supported
            // Phase 4: single key; future phases will add rotation
            return response()->json([
                'error' => 'Invalid key ID',
            ], 401);
        }

        // 6. Build canonical data and verify HMAC signature
        $canonicalData = $this->hmacService->buildCanonicalData(
            $request->method(),
            // Must match exactly what the plugin signs: the full request path
            // including the leading slash and the "/api" prefix
            // (e.g. "/api/v1/heartbeat"). $request->path() drops the leading
            // slash, so use getPathInfo() instead.
            $request->getPathInfo(),
            $request->query->all(),
            $timestamp,
            $nonce,
            $this->hmacService->getRequestBody($request)
        );

        if (!$this->hmacService->verifySignature($signature, $canonicalData, $siteSecret)) {
            return response()->json([
                'error' => 'Invalid HMAC signature',
            ], 401);
        }

        // 7. Atomically claim nonce (replay protection)
        if (!$this->nonceManager->claimNonce($siteUuid, $nonce)) {
            return response()->json([
                'error' => 'Nonce already used',
            ], 401);
        }

        // 8. Attach site to request for use by controllers
        $request->attributes->set('site', $site);

        return $next($request);
    }
}
