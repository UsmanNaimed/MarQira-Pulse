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
        $hasUpload = $request->hasFile('file');

        $validator = Validator::make($request->all(), [
            'version' => 'required|string|max:20|unique:plugin_releases,version',
            'changelog' => 'nullable|string',
            // Either upload a zip OR supply an external download URL.
            'file' => 'required_without:download_url|file|mimetypes:application/zip,application/octet-stream,application/x-zip-compressed|max:20480',
            'download_url' => 'required_without:file|nullable|url|max:500',
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

        // Extra guard: an uploaded file must actually be a .zip.
        if ($hasUpload && strtolower($request->file('file')->getClientOriginalExtension()) !== 'zip') {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => ['file' => ['The uploaded file must be a .zip archive.']],
            ], 422);
        }

        $disk = config('marqira.downloads.disk', 'releases');

        DB::beginTransaction();

        try {
            $version = $request->input('version');

            $storagePath = null;
            $downloadUrl = $request->input('download_url');
            $fileHash = $request->input('file_hash');
            $fileSize = $request->input('file_size');

            // When a zip is uploaded, store it on the releases disk, compute the
            // integrity hash/size, and default to auto-activating it so it
            // becomes the version served from downloads.marqira.com immediately.
            if ($hasUpload) {
                $file = $request->file('file');
                $realPath = $file->getRealPath();
                $fileHash = hash_file('sha256', $realPath);
                $fileSize = $file->getSize();
                $storagePath = 'marqira-connector-' . $version . '.zip';

                \Illuminate\Support\Facades\Storage::disk($disk)->putFileAs(
                    '',
                    $file,
                    $storagePath
                );
            }

            $isActive = $request->has('is_active')
                ? $request->boolean('is_active')
                : $hasUpload; // uploads auto-activate by default

            $release = PluginRelease::create([
                'version' => $version,
                'changelog' => $request->input('changelog'),
                // Temporary placeholder for uploads; replaced below once we know
                // the release id (needed to build the stream route).
                'download_url' => $downloadUrl ?: 'pending',
                'storage_path' => $storagePath,
                'file_hash' => $fileHash,
                'file_size' => $fileSize,
                'requires_wp' => $request->input('requires_wp', '5.6'),
                'requires_php' => $request->input('requires_php', '7.4'),
                'tested_up_to' => $request->input('tested_up_to'),
                'is_active' => $isActive,
                'released_at' => now(),
                'released_by' => auth()->id(),
            ]);

            // Build the public download URL for uploaded files from the
            // configured downloads origin (downloads.marqira.com → the API).
            if ($hasUpload) {
                $base = config('marqira.downloads.base_url');
                $downloadUrl = $base . '/api/v1/plugin/releases/' . $release->id . '/download';
                $release->update(['download_url' => $downloadUrl]);
            }

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
                    'uploaded' => $hasUpload,
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
                    'download_url' => $release->download_url,
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

            // Remove the stored zip (if this release was an upload).
            if ($release->storage_path) {
                $disk = config('marqira.downloads.disk', 'releases');
                \Illuminate\Support\Facades\Storage::disk($disk)->delete($release->storage_path);
            }

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
