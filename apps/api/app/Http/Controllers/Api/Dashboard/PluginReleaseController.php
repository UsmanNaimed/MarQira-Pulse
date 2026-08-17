<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PluginRelease;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Plugin Release Management Controller
 *
 * Allows platform owners to manage MarQira Connector plugin releases.
 * Only accessible by platform owners (enforced by 'owner' middleware).
 */
class PluginReleaseController extends Controller
{
    public function __construct(private TenantContext $tenantContext)
    {
    }

    /**
     * List all plugin releases.
     *
     * GET /api/dashboard/plugin-releases
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $releases = PluginRelease::orderBy('released_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $releases->map(function ($release) {
                return [
                    'id' => $release->id,
                    'version' => $release->version,
                    'changelog' => $release->changelog,
                    'download_url' => $release->download_url,
                    'file_hash' => $release->file_hash,
                    'file_size' => $release->file_size,
                    'requires_wp' => $release->requires_wp,
                    'requires_php' => $release->requires_php,
                    'tested_up_to' => $release->tested_up_to,
                    'is_active' => $release->is_active,
                    'released_at' => $release->released_at?->toIso8601String(),
                    'released_by' => $release->releasedBy ? [
                        'id' => $release->releasedBy->id,
                        'name' => $release->releasedBy->name,
                        'email' => $release->releasedBy->email,
                    ] : null,
                    'created_at' => $release->created_at?->toIso8601String(),
                ];
            }),
        ]);
    }

    /**
     * Create a new plugin release.
     *
     * POST /api/dashboard/plugin-releases
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'version' => 'required|string|max:20|unique:plugin_releases,version',
            'changelog' => 'nullable|string',
            'download_url' => 'required|url|max:500',
            'file_hash' => 'nullable|string|max:64',
            'file_size' => 'nullable|integer|min:0',
            'requires_wp' => 'nullable|string|max:20',
            'requires_php' => 'nullable|string|max:20',
            'tested_up_to' => 'nullable|string|max:20',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $release = PluginRelease::create([
                'version' => $request->input('version'),
                'changelog' => $request->input('changelog'),
                'download_url' => $request->input('download_url'),
                'file_hash' => $request->input('file_hash'),
                'file_size' => $request->input('file_size'),
                'requires_wp' => $request->input('requires_wp', '5.6'),
                'requires_php' => $request->input('requires_php', '7.4'),
                'tested_up_to' => $request->input('tested_up_to'),
                'is_active' => $request->input('is_active', false),
                'released_at' => now(),
                'released_by' => auth()->id(),
            ]);

            // If marked as active, deactivate all others.
            if ($release->is_active) {
                PluginRelease::where('id', '!=', $release->id)->update(['is_active' => false]);
            }

            // Audit log
            AuditLog::record([
                'organization_id' => $this->tenantContext->organizationId(),
                'actor_id' => auth()->id(),
                'event' => 'plugin_release.created',
                'subject_type' => 'PluginRelease',
                'subject_id' => $release->id,
                'metadata' => [
                    'version' => $release->version,
                    'is_active' => $release->is_active,
                ],
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Plugin release created successfully',
                'data' => [
                    'id' => $release->id,
                    'version' => $release->version,
                    'is_active' => $release->is_active,
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);

            return response()->json([
                'error' => 'Failed to create plugin release',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Activate a plugin release (make it the current version).
     *
     * POST /api/dashboard/plugin-releases/{id}/activate
     *
     * @param int $id Plugin release ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function activate($id)
    {
        $release = PluginRelease::findOrFail($id);

        if ($release->is_active) {
            return response()->json([
                'success' => true,
                'message' => 'This release is already active',
            ]);
        }

        DB::beginTransaction();

        try {
            $release->activate();

            // Audit log
            AuditLog::record([
                'organization_id' => $this->tenantContext->organizationId(),
                'actor_id' => auth()->id(),
                'event' => 'plugin_release.activated',
                'subject_type' => 'PluginRelease',
                'subject_id' => $release->id,
                'metadata' => [
                    'version' => $release->version,
                ],
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Version {$release->version} is now active",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);

            return response()->json([
                'error' => 'Failed to activate release',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a plugin release.
     *
     * DELETE /api/dashboard/plugin-releases/{id}
     *
     * @param int $id Plugin release ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $release = PluginRelease::findOrFail($id);

        if ($release->is_active) {
            return response()->json([
                'error' => 'Cannot delete the active release',
                'message' => 'Activate a different version first',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $version = $release->version;

            $release->delete();

            // Audit log
            AuditLog::record([
                'organization_id' => $this->tenantContext->organizationId(),
                'actor_id' => auth()->id(),
                'event' => 'plugin_release.deleted',
                'subject_type' => 'PluginRelease',
                'metadata' => [
                    'version' => $version,
                ],
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Version {$version} deleted successfully",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);

            return response()->json([
                'error' => 'Failed to delete release',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
