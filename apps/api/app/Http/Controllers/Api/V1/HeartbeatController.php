<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteHeartbeat;
use App\Models\SiteNetworkInfo;
use App\Services\Alerts\OfflineAlertService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Heartbeat controller for WordPress plugin heartbeats.
 *
 * POST /api/v1/heartbeat
 * Protected by HMAC authentication middleware
 */
class HeartbeatController extends Controller
{
    /**
     * Receive and process a site heartbeat.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function receive(Request $request, OfflineAlertService $alerts)
    {
        // Site is attached to request by HMAC middleware
        $site = $request->attributes->get('site');

        if (!$site) {
            return response()->json([
                'error' => 'Site not found in request context',
            ], 500);
        }

        // Validate heartbeat payload
        $validator = Validator::make($request->all(), [
            'domain' => 'required|string|max:255',
            'home_url' => 'nullable|url|max:500',
            'site_url' => 'nullable|url|max:500',
            'wp_version' => 'nullable|string|max:20',
            'php_version' => 'nullable|string|max:20',
            'plugin_version' => 'nullable|string|max:20',
            'server_ip' => 'nullable|ip|max:45',
            'server_hostname' => 'nullable|string|max:255',
            'server_software' => 'nullable|string|max:255',
            'is_multisite' => 'nullable|boolean',
            'network_data' => 'nullable|array',
            'origin_ip_candidate' => 'nullable|ip|max:45',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        // Capture offline state BEFORE the update so we can detect recovery.
        // A site "recovers" when it was offline and had at least one offline
        // alert sent during the outage — only then do we email a recovery notice.
        $wasOffline = $site->status === Site::STATUS_OFFLINE;
        $offlineSince = $site->offline_since;
        $alertsSentDuringOutage = (int) $site->offline_alert_count;

        DB::beginTransaction();

        try {
            // Store heartbeat record (append-only)
            SiteHeartbeat::create([
                'site_id' => $site->id,
                'organization_id' => $site->organization_id,
                'received_at' => now(),
                'wp_version' => $request->input('wp_version'),
                'php_version' => $request->input('php_version'),
                'plugin_version' => $request->input('plugin_version'),
                'server_ip' => $request->input('server_ip'),
                'server_hostname' => $request->input('server_hostname'),
                'server_software' => $request->input('server_software'),
                'origin_ip_candidate' => $request->input('origin_ip_candidate'),
                'is_multisite' => $request->input('is_multisite', false),
                'payload' => $request->all(), // Full payload as JSONB
                'created_at' => now(),
            ]);

            // Update site record with latest info
            $updateData = [
                'wp_version' => $request->input('wp_version'),
                'php_version' => $request->input('php_version'),
                'plugin_version' => $request->input('plugin_version'),
                'server_hostname' => $request->input('server_hostname'),
                'server_software' => $request->input('server_software'),
                'is_multisite' => $request->input('is_multisite', false),
                'last_heartbeat_at' => now(),
                'last_seen_at' => now(),
                'status' => Site::STATUS_ONLINE,
            ];

            // §26 IP-retention fix: only update server_ip when the heartbeat
            // provides a valid one. A null or omitted server_ip must never
            // overwrite a previously-good IP (e.g. from a successful beat before
            // the connector environment changed to one where SERVER_ADDR is
            // unavailable). The connector normalizes and omits invalid values, so
            // if server_ip is present here it passed `nullable|ip` validation above.
            if ($request->filled('server_ip')) {
                $updateData['server_ip'] = $request->input('server_ip');
            }

            // Clear offline alert tracking when a previously-offline site checks in.
            if ($wasOffline) {
                $updateData['offline_since'] = null;
                $updateData['last_offline_alert_at'] = null;
                $updateData['offline_alert_count'] = 0;
            }

            // Origin IP logic: For Phase 4, just store the candidate
            // Phase 6 will add sophisticated origin detection and verification
            if ($request->filled('origin_ip_candidate')) {
                $updateData['origin_ip'] = $request->input('origin_ip_candidate');
                $updateData['origin_ip_source'] = 'heartbeat_candidate';
                $updateData['origin_ip_confidence'] = 'medium'; // Phase 6 will refine this
            }

            $site->update($updateData);

            // If multisite, store network info
            if ($request->input('is_multisite') && $request->filled('network_data')) {
                $networkData = $request->input('network_data');

                SiteNetworkInfo::create([
                    'site_id' => $site->id,
                    'organization_id' => $site->organization_id,
                    'recorded_at' => now(),
                    'network_sites_count' => $networkData['sites_count'] ?? null,
                    'network_data' => $networkData,
                    'created_at' => now(),
                ]);
            }

            DB::commit();

            // Fire the recovery alert AFTER the transaction commits so a mail /
            // queue hiccup can never roll back the heartbeat write. Only alert
            // when the site was offline AND we had actually warned about it.
            if ($wasOffline && $alertsSentDuringOutage > 0) {
                try {
                    $alerts->sendRecoveryAlert($site, $offlineSince, $alertsSentDuringOutage);
                } catch (\Throwable $e) {
                    // Never let alerting failures break heartbeat acknowledgement.
                    report($e);
                }
            }

            return response()->json([
                'success' => true,
                'next_heartbeat_seconds' => 600, // 10 minutes
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            report($e);

            return response()->json([
                'error' => 'Heartbeat processing failed',
                'message' => 'An error occurred while processing the heartbeat.',
            ], 500);
        }
    }
}
