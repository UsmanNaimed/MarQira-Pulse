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
            'status' => 'required|string|in:in_progress,completed,failed',
            'message' => 'nullable|string|max:1000',
            'version' => 'nullable|string|max:20',
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

        // Ignore acks for a site with no command in flight (e.g. a stale retry
        // after the command was already resolved). Respond 200 so the connector
        // does not keep retrying.
        if (! in_array($site->update_command_status, [
            Site::UPDATE_CMD_DISPATCHED,
            Site::UPDATE_CMD_IN_PROGRESS,
        ], true)) {
            return response()->json([
                'success' => true,
                'ignored' => true,
            ], 200);
        }

        $update = [
            'update_command_status' => $status,
            'update_command_message' => $message,
        ];

        if ($status === Site::UPDATE_CMD_COMPLETED || $status === Site::UPDATE_CMD_FAILED) {
            $update['update_command_completed_at'] = now();
        }

        // A successful update also updates the reported plugin version so the
        // dashboard immediately reflects the new version without waiting for the
        // next heartbeat.
        if ($status === Site::UPDATE_CMD_COMPLETED && $reportedVersion) {
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
