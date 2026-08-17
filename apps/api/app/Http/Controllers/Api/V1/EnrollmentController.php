<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\EnrollmentToken;
use App\Models\Site;
use App\Services\Encryption\SecretEncryptor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Public enrollment controller for WordPress plugin enrollment.
 *
 * POST /api/v1/enrollment
 * Unauthenticated (uses enrollment token)
 */
class EnrollmentController extends Controller
{
    public function __construct(
        private SecretEncryptor $secretEncryptor
    ) {}

    /**
     * Enroll a new WordPress site using an enrollment token.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function enroll(Request $request)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'domain' => 'required|string|max:255',
            'home_url' => 'required|url|max:500',
            'site_url' => 'required|url|max:500',
            'wp_version' => 'nullable|string|max:20',
            'php_version' => 'nullable|string|max:20',
            'plugin_version' => 'required|string|max:20',
            'server_ip' => 'nullable|ip|max:45',
            'server_hostname' => 'nullable|string|max:255',
            'server_software' => 'nullable|string|max:255',
            'is_multisite' => 'nullable|boolean',
            'network_data' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        $tokenValue = $request->input('token');
        $tokenHash = hash('sha256', $tokenValue);

        // Find and validate enrollment token
        $enrollmentToken = EnrollmentToken::where('token_hash', $tokenHash)
            ->with('organization')
            ->first();

        if (!$enrollmentToken) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid enrollment token',
            ], 401);
        }

        if ($enrollmentToken->isExpired()) {
            return response()->json([
                'success' => false,
                'error' => 'Enrollment token has expired',
            ], 401);
        }

        if ($enrollmentToken->isUsed()) {
            return response()->json([
                'success' => false,
                'error' => 'Enrollment token has already been used',
            ], 401);
        }

        // Resolve the owner of this site from the enrollment token. Owner
        // isolation (§2/§4) hinges on this: whoever created the connection code
        // owns any site enrolled with it. May be null for legacy tokens.
        $ownerUserId = $enrollmentToken->created_by;

        // Normalize the domain so duplicate detection is host-based and immune
        // to scheme / www / trailing path differences (§9/§10).
        $domainNormalized = Site::normalizeDomain(
            $request->input('domain') ?: $request->input('home_url')
        );

        // Generate fresh site credentials. On re-enrollment we ROTATE the
        // secret so an old plugin install can never keep talking after a
        // reconnect, while the site row (uuid + history) is preserved.
        $siteSecret = base64_encode(random_bytes(32));
        $encryptedSecret = $this->secretEncryptor->encrypt($siteSecret);
        $kid = $this->secretEncryptor->keyId();

        // Create or reuse the site and mark the token as used in a transaction.
        DB::beginTransaction();

        try {
            // Duplicate-site prevention (§9/§10): look for an existing active
            // (non-revoked) site with the same normalized domain in this org.
            $existing = null;
            if ($domainNormalized !== null) {
                $existing = Site::query()
                    ->where('organization_id', $enrollmentToken->organization_id)
                    ->where('domain_normalized', $domainNormalized)
                    ->whereNull('revoked_at')
                    ->lockForUpdate()
                    ->first();
            }

            $isReenrollment = false;

            if ($existing !== null) {
                // Prevent silent ownership transfer: if the existing site is
                // owned by someone else, reject rather than hijacking it.
                if (
                    $existing->owner_user_id !== null
                    && $ownerUserId !== null
                    && $existing->owner_user_id !== $ownerUserId
                ) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'error' => 'site_already_enrolled',
                        'message' => 'This website is already connected under a different account. Ask the owner to remove it first, or contact your administrator.',
                    ], 409);
                }

                // Reuse the existing row: rotate the secret, refresh metadata,
                // and keep the same uuid + heartbeat history so the site never
                // appears as a brand-new duplicate.
                $isReenrollment = true;
                $site = $existing;
                $site->fill([
                    'domain' => $request->input('domain'),
                    'domain_normalized' => $domainNormalized,
                    'home_url' => $request->input('home_url'),
                    'site_url' => $request->input('site_url'),
                    'status' => Site::STATUS_UNKNOWN,
                    'site_secret_encrypted' => $encryptedSecret,
                    'site_secret_kid' => $kid,
                    'wp_version' => $request->input('wp_version'),
                    'php_version' => $request->input('php_version'),
                    'plugin_version' => $request->input('plugin_version'),
                    'server_hostname' => $request->input('server_hostname'),
                    'server_software' => $request->input('server_software'),
                    'is_multisite' => $request->input('is_multisite', false),
                    'enrolled_at' => now(),
                    'disconnected_at' => null,
                ]);
                // Only claim ownership if the row was previously unowned.
                if ($site->owner_user_id === null) {
                    $site->owner_user_id = $ownerUserId;
                }
                // Preserve stored IPs unless a fresh value is supplied (§26).
                if ($request->filled('server_ip')) {
                    $site->server_ip = $request->input('server_ip');
                }
                $site->save();
            } else {
                $site = Site::create([
                    'organization_id' => $enrollmentToken->organization_id,
                    'owner_user_id' => $ownerUserId,
                    'domain' => $request->input('domain'),
                    'domain_normalized' => $domainNormalized,
                    'home_url' => $request->input('home_url'),
                    'site_url' => $request->input('site_url'),
                    'status' => Site::STATUS_UNKNOWN, // 'online' on first heartbeat
                    'site_secret_encrypted' => $encryptedSecret,
                    'site_secret_kid' => $kid,
                    'wp_version' => $request->input('wp_version'),
                    'php_version' => $request->input('php_version'),
                    'plugin_version' => $request->input('plugin_version'),
                    'server_ip' => $request->input('server_ip'),
                    'server_hostname' => $request->input('server_hostname'),
                    'server_software' => $request->input('server_software'),
                    'is_multisite' => $request->input('is_multisite', false),
                    'enrolled_at' => now(),
                ]);
            }

            // Mark token as used
            $enrollmentToken->update([
                'used_at' => now(),
                'used_by_site_id' => $site->id,
            ]);

            // Log to audit trail
            AuditLog::record([
                'organization_id' => $enrollmentToken->organization_id,
                'event' => $isReenrollment ? 'site_reenrolled' : 'site_enrolled',
                'subject_type' => 'site',
                'subject_id' => $site->id,
                'subject_uuid' => $site->uuid,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'domain' => $site->domain,
                    'plugin_version' => $site->plugin_version,
                    'enrollment_token_uuid' => $enrollmentToken->uuid,
                    'reenrollment' => $isReenrollment,
                ],
            ]);

            DB::commit();

            // Return credentials (site_secret shown only once)
            return response()->json([
                'success' => true,
                'site_uuid' => $site->uuid,
                'site_secret' => $siteSecret, // Base64-encoded, 32 bytes
                'kid' => $kid,
                'api_url' => config('app.url'),
                'heartbeat_interval_seconds' => 600, // 10 minutes
                'config' => [
                    'allowed_ips_url' => route('config.allowed-ips'),
                    'cloudflare_ranges_url' => route('config.cloudflare-ranges'),
                ],
            ], $isReenrollment ? 200 : 201);

        } catch (\Exception $e) {
            DB::rollBack();

            report($e);

            return response()->json([
                'success' => false,
                'error' => 'Enrollment failed',
                'message' => 'An error occurred during enrollment. Please try again.',
            ], 500);
        }
    }
}
