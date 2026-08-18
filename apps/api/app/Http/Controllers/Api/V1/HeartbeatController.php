<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OriginIpHistory;
use App\Models\Site;
use App\Models\SiteHeartbeat;
use App\Models\SiteNetworkInfo;
use App\Models\SiteVisitorMetric;
use App\Services\Alerts\OfflineAlertService;
use App\Services\OriginDetector;
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
     * @param OfflineAlertService $alerts
     * @param OriginDetector $originDetector
     * @return \Illuminate\Http\JsonResponse
     */
    public function receive(Request $request, OfflineAlertService $alerts, OriginDetector $originDetector)
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
            // Update inventory (§13): reported by connector 1.2.4+. Older
            // connectors omit it, in which case we leave the stored counts as-is.
            'updates' => 'nullable|array',
            'updates.core' => 'nullable|boolean',
            'updates.plugins' => 'nullable|integer|min:0',
            'updates.themes' => 'nullable|integer|min:0',
            // Visitor metrics (Phase 8): reported by connector 1.2.5+. Daily
            // aggregated visitor/pageview counts (privacy-safe, no PII).
            'visitor_metrics' => 'nullable|array',
            'visitor_metrics.date' => 'nullable|date',
            'visitor_metrics.unique_visitors' => 'nullable|integer|min:0',
            'visitor_metrics.pageviews' => 'nullable|integer|min:0',
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
                // A real heartbeat is the strongest possible liveness signal, so
                // reset the active-probe verification counters. This makes the
                // heartbeat an immediate recovery path and prevents a stale probe
                // failure streak from lingering once the connector checks back in.
                'consecutive_check_failures' => 0,
                'consecutive_check_successes' => 0,
            ];

            // Update inventory (§13): store the connector-reported counts of
            // pending core/plugin/theme updates. Only overwrite when the
            // connector actually sent an `updates` block (1.2.4+), so heartbeats
            // from older connectors never zero out a known inventory.
            if ($request->filled('updates') && is_array($request->input('updates'))) {
                $updates = $request->input('updates');
                $updateData['core_update_available'] = (bool) ($updates['core'] ?? false);
                $updateData['plugin_updates_count'] = (int) ($updates['plugins'] ?? 0);
                $updateData['theme_updates_count'] = (int) ($updates['themes'] ?? 0);
                $updateData['updates_checked_at'] = now();
            }

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

            // Phase 6: Sophisticated origin IP detection using DNS analysis
            $domain = $request->input('domain');
            $serverIp = $request->filled('server_ip') ? $request->input('server_ip') : null;
            
            // Analyze origin using DNS + server IP
            $originAnalysis = $originDetector->analyze($domain, $serverIp);
            
            // Capture current state for comparison
            $previousOriginIp = $site->origin_ip;
            $previousConfidence = $site->origin_ip_confidence;
            
            // Determine if we should update (only if analysis yielded a result)
            if ($originAnalysis['origin_ip'] !== null) {
                $updateData['origin_ip'] = $originAnalysis['origin_ip'];
                $updateData['origin_ip_source'] = $originAnalysis['source'];
                $updateData['origin_ip_confidence'] = $originAnalysis['confidence'];
                
                // If origin changed or confidence improved, log it in history
                $originChanged = $previousOriginIp !== $originAnalysis['origin_ip'];
                $confidenceChanged = $previousConfidence !== $originAnalysis['confidence'];
                
                if ($originChanged || $confidenceChanged) {
                    OriginIpHistory::create([
                        'site_id' => $site->id,
                        'organization_id' => $site->organization_id,
                        'event_type' => 'detected',
                        'origin_ip' => $originAnalysis['origin_ip'],
                        'previous_origin_ip' => $previousOriginIp,
                        'source' => $originAnalysis['source'],
                        'confidence' => $originAnalysis['confidence'],
                        'previous_confidence' => $previousConfidence,
                        'verified' => false,
                        'metadata' => $originAnalysis['metadata'] ?? [],
                        'recorded_at' => now(),
                    ]);
                }
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

            // Phase 8 — Visitor metrics: store daily aggregated visitor data
            // sent by connector 1.2.5+ (privacy-safe, no PII). Upsert on
            // (site_id, date) so re-sent metrics for the same day update instead
            // of duplicating.
            if ($request->filled('visitor_metrics')) {
                $metrics = $request->input('visitor_metrics');
                
                if (isset($metrics['date'], $metrics['unique_visitors'], $metrics['pageviews'])) {
                    SiteVisitorMetric::updateOrCreate(
                        [
                            'site_id' => $site->id,
                            'date' => $metrics['date'],
                        ],
                        [
                            'organization_id' => $site->organization_id,
                            'unique_visitors' => (int) $metrics['unique_visitors'],
                            'pageviews' => (int) $metrics['pageviews'],
                            'recorded_at' => now(),
                        ]
                    );
                }
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

            $response = [
                'success' => true,
                'next_heartbeat_seconds' => 600, // 10 minutes
            ];

            // Remote update command channel: if the dashboard has queued an
            // "update this site now" command (status = pending), hand it to the
            // connector in this heartbeat response and flip the status to
            // dispatched. If the site is already running the target version,
            // resolve the command as completed instead of dispatching again.
            $command = $this->buildPendingUpdateCommand($site);
            if ($command !== null) {
                $response['commands'] = [$command];
            }

            return response()->json($response, 200);

        } catch (\Exception $e) {
            DB::rollBack();

            report($e);

            return response()->json([
                'error' => 'Heartbeat processing failed',
                'message' => 'An error occurred while processing the heartbeat.',
            ], 500);
        }
    }

    /**
     * Resolve any pending remote update command for a site into a command the
     * connector can act on.
     *
     * Returns the command array to embed in the heartbeat response, or null
     * when there is nothing to hand over. Side effects:
     *  - pending + already at/above target version -> mark completed (no command).
     *  - pending + update still needed             -> mark dispatched, emit command.
     *
     * Only "pending" is acted on; dispatched/in_progress/completed/failed are
     * left untouched so a command is delivered exactly once per request (the
     * dashboard re-queues by setting pending again).
     *
     * @param Site $site
     * @return array<string, string>|null
     */
    private function buildPendingUpdateCommand(Site $site): ?array
    {
        if ($site->update_command_status !== Site::UPDATE_CMD_PENDING) {
            return null;
        }

        $type = $site->update_command_type ?: Site::UPDATE_CMD_TYPE_PLUGIN;
        $target = $site->update_command_target_version;

        // Connector self-update only: if the site already reports the target (or
        // newer) version there is nothing to do. Core/plugin maintenance always
        // dispatches (WordPress decides what actually needs upgrading).
        if ($type === Site::UPDATE_CMD_TYPE_PLUGIN
            && $target && $site->plugin_version
            && version_compare($site->plugin_version, $target, '>=')) {
            $site->update([
                'update_command_status' => Site::UPDATE_CMD_COMPLETED,
                'update_command_completed_at' => now(),
                'update_command_message' => 'Site already running version ' . $site->plugin_version . '.',
            ]);

            return null;
        }

        $site->update([
            'update_command_status' => Site::UPDATE_CMD_DISPATCHED,
            'update_command_dispatched_at' => now(),
        ]);

        // Map the stored maintenance type to the connector command verb.
        $commandType = [
            Site::UPDATE_CMD_TYPE_PLUGIN => 'update_plugin',
            Site::UPDATE_CMD_TYPE_CORE => 'update_core',
            Site::UPDATE_CMD_TYPE_PLUGINS => 'update_all_plugins',
            Site::UPDATE_CMD_TYPE_THEMES => 'update_all_themes',
        ][$type] ?? 'update_plugin';

        $command = ['type' => $commandType];

        if ($type === Site::UPDATE_CMD_TYPE_PLUGIN) {
            $command['target_version'] = (string) $target;
        }

        return $command;
    }
}
