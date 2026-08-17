<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\HeartbeatResource;
use App\Http\Resources\SiteDetailResource;
use App\Http\Resources\SitePostResource;
use App\Http\Resources\SiteResource;
use App\Http\Resources\SiteUserResource;
use App\Models\AuditLog;
use App\Models\Site;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Websites list + detail for the dashboard. All queries are tenant-scoped.
 */
class SiteController extends Controller
{
    /**
     * Columns the table may be sorted by (whitelist to avoid SQL injection via
     * an arbitrary "sort" parameter).
     *
     * @var array<int, string>
     */
    private const SORTABLE = [
        'domain', 'status', 'wp_version', 'php_version',
        'plugin_version', 'last_heartbeat_at', 'enrolled_at', 'created_at',
    ];

    public function __construct(private TenantContext $tenantContext) {}

    /**
     * GET /api/dashboard/sites
     *
     * Supports: search (q), status filter, sort + direction, pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $orgId = $this->tenantContext->organizationId();

        // Owner sees every site; Subscriber only owned sites. Revoked sites are
        // hidden from the active list.
        $query = Site::query()
            ->where('organization_id', $orgId)
            ->visibleTo($request->user())
            ->active();

        // Search across domain and URLs.
        if ($search = trim((string) $request->query('q', ''))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
            $query->where(function ($q) use ($like) {
                $q->where('domain', 'like', $like)
                    ->orWhere('home_url', 'like', $like)
                    ->orWhere('site_url', 'like', $like)
                    ->orWhere('server_ip', 'like', $like)
                    ->orWhere('origin_ip', 'like', $like);
            });
        }

        // Status filter.
        $status = $request->query('status');
        if ($status && in_array($status, ['online', 'offline', 'unknown'], true)) {
            $query->where('status', $status);
        }

        // "Needs attention" filter (unreachable/unverified origin).
        if ($request->boolean('needs_attention')) {
            $query->where(function ($q) {
                $q->whereNull('origin_ip')->orWhere('origin_ip_verified', false);
            });
        }

        // Sorting (whitelisted column + direction).
        $sort = $request->query('sort', 'last_heartbeat_at');
        if (! in_array($sort, self::SORTABLE, true)) {
            $sort = 'last_heartbeat_at';
        }
        $direction = strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $direction);

        // Pagination (bounded page size).
        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(5, min($perPage, 100));

        $sites = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'data' => SiteResource::collection($sites->items()),
            'meta' => [
                'current_page' => $sites->currentPage(),
                'last_page' => $sites->lastPage(),
                'per_page' => $sites->perPage(),
                'total' => $sites->total(),
                'from' => $sites->firstItem(),
                'to' => $sites->lastItem(),
            ],
        ]);
    }

    /**
     * GET /api/dashboard/sites/{uuid}
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $site = $this->findSiteOrFail($request, $uuid);

        return response()->json([
            'data' => new SiteDetailResource($site),
        ]);
    }

    /**
     * DELETE /api/dashboard/sites/{uuid}
     *
     * "Remove Website": revoke the site's connection (soft, reversible-in-DB).
     * The site's credentials become invalid, the connector is told to
     * self-disconnect on its next request (HTTP 403 `site_revoked`), and the
     * record is hidden from the active dashboard list. The row is retained (as
     * revoked) so the connector can still discover it was revoked — we never
     * hard-delete here (see §12). A Subscriber may only remove their own sites;
     * the Owner may remove any (enforced by SitePolicy).
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $site = $this->findSiteOrFail($request, $uuid);

        $this->authorize('delete', $site);

        if (! $site->isRevoked()) {
            $site->update([
                'status' => Site::STATUS_REVOKED,
                'revoked_at' => now(),
                'revoked_by' => $request->user()->id,
                'disconnected_at' => now(),
            ]);

            AuditLog::record([
                'organization_id' => $site->organization_id,
                'actor_id' => $request->user()->id,
                'actor_type' => 'user',
                'event' => 'site.revoked',
                'subject_type' => 'site',
                'subject_id' => $site->id,
                'subject_uuid' => $site->uuid,
                'ip_address' => $request->ip(),
                'metadata' => [
                    'domain' => $site->domain,
                    'removed_by_role' => $request->user()->platform_role,
                ],
            ]);
        }

        return response()->json([
            'message' => 'Website removed. The connector will disconnect on its next check-in.',
            'status' => $site->status,
        ]);
    }

    /**
     * GET /api/dashboard/sites/{uuid}/heartbeats
     *
     * Connection history — most recent heartbeats first.
     */
    public function heartbeats(Request $request, string $uuid): JsonResponse
    {
        $site = $this->findSiteOrFail($request, $uuid);

        $limit = max(5, min((int) $request->query('limit', 50), 200));

        $heartbeats = $site->heartbeats()
            ->orderByDesc('received_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => HeartbeatResource::collection($heartbeats),
        ]);
    }

    /**
     * GET /api/dashboard/sites/{uuid}/users
     *
     * WordPress users & login data — most recent snapshots first.
     * Returns the latest snapshot per wp_user_id to avoid showing duplicates.
     */
    public function users(Request $request, string $uuid): JsonResponse
    {
        $site = $this->findSiteOrFail($request, $uuid);

        $perPage = max(10, min((int) $request->query('per_page', 50), 200));

        // Get the most recent snapshot for each unique wp_user_id using window function
        $users = $site->users()
            ->selectRaw('DISTINCT ON (wp_user_id) *')
            ->orderBy('wp_user_id')
            ->orderByDesc('snapshot_at')
            ->paginate($perPage);

        return response()->json([
            'data' => SiteUserResource::collection($users->items()),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * GET /api/dashboard/sites/{uuid}/posts
     *
     * WordPress posts & content data — most recent snapshots first.
     * Returns the latest snapshot per wp_post_id to avoid showing duplicates.
     */
    public function posts(Request $request, string $uuid): JsonResponse
    {
        $site = $this->findSiteOrFail($request, $uuid);

        $perPage = max(10, min((int) $request->query('per_page', 50), 200));

        // Optional filter by post_status (publish, future, etc.)
        $status = $request->query('status');

        // Get the most recent snapshot for each unique wp_post_id using DISTINCT ON
        $query = $site->posts()
            ->selectRaw('DISTINCT ON (wp_post_id) *')
            ->orderBy('wp_post_id')
            ->orderByDesc('snapshot_at');

        if ($status && in_array($status, ['publish', 'future', 'draft'], true)) {
            $query->where('post_status', $status);
        }

        $posts = $query->paginate($perPage);

        return response()->json([
            'data' => SitePostResource::collection($posts->items()),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    /**
     * GET /api/dashboard/sites/{uuid}/update-status
     *
     * Compares the connector version this site is currently running (from its
     * latest heartbeat / stored plugin_version) against the currently active
     * plugin release, so the dashboard's per-site "Updates" tab can show whether
     * an update is available and surface the release details.
     */
    public function updateStatus(Request $request, string $uuid): JsonResponse
    {
        $site = $this->findSiteOrFail($request, $uuid);

        $current = $site->plugin_version;
        $active  = \App\Models\PluginRelease::getActive();

        // No active release published yet — nothing to compare against.
        if (! $active) {
            return response()->json([
                'data' => [
                    'current_version' => $current,
                    'latest_version'  => null,
                    'update_available' => false,
                    'is_up_to_date'   => false,
                    'has_active_release' => false,
                    'release'         => null,
                ],
            ]);
        }

        // A site with no reported version can't be compared reliably; treat as
        // "update available" so it surfaces for attention rather than hiding.
        $updateAvailable = $current
            ? version_compare($active->version, $current, '>')
            : true;

        return response()->json([
            'data' => [
                'current_version'    => $current,
                'latest_version'     => $active->version,
                'update_available'   => $updateAvailable,
                'is_up_to_date'      => $current ? ! $updateAvailable : false,
                'has_active_release' => true,
                'release'            => [
                    'id'           => $active->id,
                    'version'      => $active->version,
                    'changelog'    => $active->changelog,
                    'download_url' => $active->download_url,
                    'file_hash'    => $active->file_hash,
                    'file_size'    => $active->file_size,
                    'requires_wp'  => $active->requires_wp,
                    'requires_php' => $active->requires_php,
                    'tested_up_to' => $active->tested_up_to,
                    'released_at'  => $active->released_at?->toIso8601String(),
                ],
            ],
        ]);
    }

    /**
     * Look up a site by UUID within the current tenant and the caller's
     * visibility scope, or 404. This prevents a Subscriber from reaching another
     * Subscriber's site by UUID (a 404 rather than 403 avoids leaking existence).
     */
    private function findSiteOrFail(Request $request, string $uuid): Site
    {
        $orgId = $this->tenantContext->organizationId();

        return Site::query()
            ->where('organization_id', $orgId)
            ->visibleTo($request->user())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }
}
