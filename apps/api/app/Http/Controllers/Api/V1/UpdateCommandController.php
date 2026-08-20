<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Update-command acknowledgement endpoint for the connector.
 *
 * POST /api/v1/update-command/ack (HMAC-authenticated)
 *
 * After the connector receives an "update_plugin" command in a heartbeat
 * response and runs the WordPress plugin upgrader, it reports the outcome here
 * so the dashboard can show live progress and resolve the command.
 */
class UpdateCommandController extends Controller
{
    /**
     * Record the connector's report of an update command outcome.
     */
    public function ack(Request $request)
    {
        /** @var Site|null $site */
        $site = $request->attributes->get('site');

        if (! $site) {
            return response()->json([
                'error' => 'Site not found in request context',
            ], 500);
        }

        $validator = Validator::make($request->all(), [
            // Granular progress states let the dashboard show a live, honest
            // progress bar (queued -> starting -> downloading -> installing ->
            // verifying -> completed) plus the terminal failed / rolled_back.
            'status' => 'required|string|in:queued,starting,downloading,installing,in_progress,verifying,completed,failed,rolled_back',
            'message' => 'nullable|string|max:1000',
            'version' => 'nullable|string|max:20',
            'command_id' => 'nullable|string|max:64',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        $status = $request->input('status');
        $message = $request->input('message');
        $reportedVersion = $request->input('version');
        $ackCommandId = $request->input('command_id');

        // Ignore acks for a site with no command in flight (e.g. a stale retry
        // after the command was already resolved). Respond 200 so the connector
        // does not keep retrying.
        if (! $site->isUpdateInFlight()) {
            return response()->json([
                'success' => true,
                'ignored' => true,
                'reason' => 'no_command_in_flight',
            ], 200);
        }

        // Ignore acks whose command_id does not match the command currently in
        // flight — this rejects late acks from a superseded command and keeps
        // the live status correct. Acks without a command_id (older connectors)
        // are accepted for backward compatibility.
        if ($ackCommandId && $site->update_command_id && ! hash_equals((string) $site->update_command_id, (string) $ackCommandId)) {
            return response()->json([
                'success' => true,
                'ignored' => true,
                'reason' => 'command_id_mismatch',
            ], 200);
        }

        $update = [
            'update_command_status' => $status,
            'update_command_message' => $message,
        ];

        if (in_array($status, Site::UPDATE_CMD_TERMINAL, true)) {
            $update['update_command_completed_at'] = now();
        }

        // A successful connector self-update also updates the reported plugin
        // version so the dashboard immediately reflects the new version without
        // waiting for the next heartbeat. Core / all-plugin updates must NOT
        // overwrite the connector version (the ack version, if any, is unrelated).
        if ($status === Site::UPDATE_CMD_COMPLETED
            && $reportedVersion
            && ($site->update_command_type ?: Site::UPDATE_CMD_TYPE_PLUGIN) === Site::UPDATE_CMD_TYPE_PLUGIN) {
            $update['plugin_version'] = $reportedVersion;
        }

        $site->update($update);

        AuditLog::record([
            'organization_id' => $site->organization_id,
            'actor_type' => 'connector',
            'event' => 'site.update_' . $status,
            'subject_type' => 'site',
            'subject_id' => $site->id,
            'subject_uuid' => $site->uuid,
            'ip_address' => $request->ip(),
            'metadata' => [
                'domain' => $site->domain,
                'update_type' => $site->update_command_type,
                'target_version' => $site->update_command_target_version,
                'reported_version' => $reportedVersion,
                'message' => $message,
            ],
        ]);

        return response()->json([
            'success' => true,
        ], 200);
    }
}
