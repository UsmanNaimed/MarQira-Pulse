<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Explicit connector lifecycle status signals.
 *
 * POST /api/v1/site-status
 * Protected by HMAC authentication middleware.
 *
 * The connector sends an explicit "offline" signal from its deactivation hook
 * (before it stops running) and an "online" signal from its activation hook, so
 * the dashboard reflects the connector's real state immediately instead of
 * waiting for the passive heartbeat-timeout watchdog (up to ~30 minutes) to
 * flip a deactivated site offline.
 */
class SiteStatusController extends Controller
{
    /**
     * Receive an explicit online/offline lifecycle signal from the connector.
     */
    public function update(Request $request)
    {
        // Site is attached to the request by the HMAC middleware.
        $site = $request->attributes->get('site');

        if (! $site) {
            return response()->json([
                'error' => 'Site not found in request context',
            ], 500);
        }

        $validator = Validator::make($request->all(), [
            'state' => 'required|string|in:online,offline',
            'reason' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        // A revoked site never comes back online via a lifecycle signal.
        if ($site->isRevoked()) {
            return response()->json([
                'success' => true,
                'ignored' => true,
                'reason' => 'revoked',
            ], 200);
        }

        $state = $request->input('state');
        $reason = $request->input('reason', $state === 'offline' ? 'connector_deactivated' : 'connector_activated');

        if ($state === 'offline') {
            $update = [
                'status' => Site::STATUS_OFFLINE,
            ];
            // Mark the start of the outage only if not already offline, so the
            // existing offline-alert cadence is preserved.
            if ($site->status !== Site::STATUS_OFFLINE) {
                $update['offline_since'] = now();
            }
            $site->update($update);
        } else {
            // Coming online: mirror the heartbeat's recovery bookkeeping so a
            // previously-offline site is cleanly reset.
            $site->update([
                'status' => Site::STATUS_ONLINE,
                'last_seen_at' => now(),
                'offline_since' => null,
                'last_offline_alert_at' => null,
                'offline_alert_count' => 0,
            ]);
        }

        AuditLog::record([
            'organization_id' => $site->organization_id,
            'actor_type' => 'connector',
            'event' => 'site.connector_' . $state,
            'subject_type' => 'site',
            'subject_id' => $site->id,
            'subject_uuid' => $site->uuid,
            'ip_address' => $request->ip(),
            'metadata' => [
                'domain' => $site->domain,
                'state' => $state,
                'reason' => $reason,
            ],
        ]);

        return response()->json([
            'success' => true,
            'status' => $site->status,
        ], 200);
    }
}
