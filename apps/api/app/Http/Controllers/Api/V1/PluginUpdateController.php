<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PluginRelease;
use Illuminate\Http\Request;

/**
 * Plugin Update Server Controller
 *
 * Implements the WordPress plugin update API for the MarQira Connector.
 * This allows WordPress sites to automatically discover and download updates.
 */
class PluginUpdateController extends Controller
{
    /**
     * Check for plugin updates.
     *
     * WordPress calls this endpoint to check if a newer version is available.
     *
     * GET /api/v1/plugin/update-check?version=1.0.0
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function check(Request $request)
    {
        $currentVersion = $request->query('version');
        
        if (!$currentVersion) {
            return response()->json([
                'error' => 'Missing version parameter',
            ], 400);
        }

        // Get the active release
        $activeRelease = PluginRelease::getActive();

        if (!$activeRelease) {
            return response()->json([
                'update_available' => false,
                'message' => 'No active release available',
            ]);
        }

        // Compare versions (simple semantic version comparison)
        $updateAvailable = version_compare($activeRelease->version, $currentVersion, '>');

        if (!$updateAvailable) {
            return response()->json([
                'update_available' => false,
                'current_version' => $currentVersion,
                'latest_version' => $activeRelease->version,
            ]);
        }

        // Return update information in WordPress-compatible format
        return response()->json([
            'update_available' => true,
            'version' => $activeRelease->version,
            'download_url' => $activeRelease->download_url,
            'changelog' => $activeRelease->changelog,
            'requires_wp' => $activeRelease->requires_wp,
            'requires_php' => $activeRelease->requires_php,
            'tested_up_to' => $activeRelease->tested_up_to,
            'file_size' => $activeRelease->file_size,
            'file_hash' => $activeRelease->file_hash,
            'released_at' => $activeRelease->released_at?->toIso8601String(),
        ]);
    }

    /**
     * Get plugin information (for "View Details" link in WP admin).
     *
     * GET /api/v1/plugin/info?version=1.0.0
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function info(Request $request)
    {
        $activeRelease = PluginRelease::getActive();

        if (!$activeRelease) {
            return response()->json([
                'error' => 'No active release available',
            ], 404);
        }

        return response()->json([
            'name' => 'MarQira Connector',
            'slug' => 'marqira-connector',
            'version' => $activeRelease->version,
            'author' => 'MarQira',
            'homepage' => 'https://marqira.com',
            'download_link' => $activeRelease->download_url,
            'requires' => $activeRelease->requires_wp,
            'requires_php' => $activeRelease->requires_php,
            'tested' => $activeRelease->tested_up_to,
            'last_updated' => $activeRelease->released_at?->toIso8601String(),
            'sections' => [
                'changelog' => $activeRelease->changelog ?? 'No changelog available.',
            ],
        ]);
    }

    /**
     * Download the latest plugin release.
     *
     * GET /api/v1/plugin/download
     *
     * This can either redirect to the download URL or stream the file directly
     * if stored locally. For now, we'll redirect.
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function download()
    {
        $activeRelease = PluginRelease::getActive();

        if (!$activeRelease) {
            return response()->json([
                'error' => 'No active release available',
            ], 404);
        }

        // If the active release was uploaded, stream it directly.
        if ($activeRelease->storage_path) {
            return $this->streamRelease($activeRelease);
        }

        // Otherwise redirect to the external download URL (S3, CDN, etc.)
        return redirect($activeRelease->download_url);
    }

    /**
     * Download a specific uploaded plugin release by id.
     *
     * GET /api/v1/plugin/releases/{id}/download
     *
     * This is the public origin that download links from the dashboard upload
     * flow point to (downloads.marqira.com → this route). It streams the stored
     * zip from the releases disk with WordPress-friendly download headers.
     *
     * @param int $id
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function downloadById($id)
    {
        $release = PluginRelease::find($id);

        if (!$release) {
            return response()->json(['error' => 'Release not found'], 404);
        }

        if (!$release->storage_path) {
            // Release has no stored file (external URL only) — redirect.
            return redirect($release->download_url);
        }

        return $this->streamRelease($release);
    }

    /**
     * Stream a stored release zip from the releases disk.
     *
     * @param PluginRelease $release
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\JsonResponse
     */
    private function streamRelease(PluginRelease $release)
    {
        $disk = \Illuminate\Support\Facades\Storage::disk(config('marqira.downloads.disk', 'releases'));

        if (!$disk->exists($release->storage_path)) {
            return response()->json(['error' => 'Release file not found'], 404);
        }

        $filename = 'marqira-connector-' . $release->version . '.zip';

        return $disk->download($release->storage_path, $filename, [
            'Content-Type' => 'application/zip',
        ]);
    }
}
