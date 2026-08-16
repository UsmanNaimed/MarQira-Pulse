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

        // Generate site credentials
        $siteSecret = base64_encode(random_bytes(32));
        $encryptedSecret = $this->secretEncryptor->encrypt($siteSecret);
        $kid = $this->secretEncryptor->keyId();

        // Create site and mark token as used in a transaction
        DB::beginTransaction();

        try {
            $site = Site::create([
                'organization_id' => $enrollmentToken->organization_id,
                'domain' => $request->input('domain'),
                'home_url' => $request->input('home_url'),
                'site_url' => $request->input('site_url'),
                'status' => 'unknown', // Will be set to 'online' on first heartbeat
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

            // Mark token as used
            $enrollmentToken->update([
                'used_at' => now(),
                'used_by_site_id' => $site->id,
            ]);

            // Log to audit trail
            AuditLog::record([
                'organization_id' => $enrollmentToken->organization_id,
                'event' => 'site_enrolled',
                'subject_type' => 'site',
                'subject_id' => $site->id,
                'subject_uuid' => $site->uuid,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'domain' => $site->domain,
                    'plugin_version' => $site->plugin_version,
                    'enrollment_token_uuid' => $enrollmentToken->uuid,
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
            ], 201);

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
