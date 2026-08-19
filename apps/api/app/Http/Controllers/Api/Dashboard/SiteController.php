<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Concerns\ScopesToAccount;
use App\Http\Controllers\Controller;
use App\Http\Resources\HeartbeatResource;
use App\Http\Resources\SiteDetailResource;
use App\Http\Resources\SitePostResource;
use App\Http\Resources\SiteResource;
use App\Http\Resources\SiteUserResource;
use App\Models\AuditLog;
use App\Models\Site;
use App\Services\TenantContext;
use App\Services\VisitorAnalytics;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

/**
 * Websites list + detail for the dashboard. All queries are tenant-scoped.
 */
class SiteController extends Controller
{
    use ScopesToAccount;

    /**
     * Columns the table may be sorted by (whitelist to avoid SQL injection via
     * an arbitrary "sort" parameter).
     *
     * @var array<int, string>
     */
    private const SORTABLE = [
        'domain', 'status', 'wp_version', 'php_version',
        'plugin_version', 'last_seen_at', 'last_heartbeat_at', 'enrolled_at', 'created_at',
    ];

    public function __construct(private TenantContext $tenantContext) {}

    /**
     * GET /api/dashboard/sites
     *
     * Supports: search (q), status filter, sort + direction, pagination.
     */
    public function index(Request $request): JsonResponse
    {
        // Fully tenant + account scoped (visibleTo + optional owner-selected
        // account). Revoked sites are hidden from the active list. See the
        // ScopesToAccount trait — the single authorization path (§8/§14).
        $query = $this->scopedSitesQuery($request)->with('owner:id,uuid,name,email');

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

        // Sorting (whitelisted column + direction). Default to last_seen_at —
        // the verified-liveness timestamp (real heartbeat OR successful active
        // probe) — so the Websites table's "Last seen" column is consistent with
        // the website detail view and refreshes on the platform's probe cadence
        // instead of drifting with the customer's sporadic WP-Cron heartbeat.
        $sort = $request->query('sort', 'last_seen_at');
        if (! in_array($sort, self::SORTABLE, true)) {
            $sort = 'last_seen_at';
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

        // WordPress users are stored as append-only snapshots: the same
        // wp_user_id is re-inserted on every collection run, so the raw table
        // accumulates duplicates over time. We only ever want the LATEST
        // snapshot per user. `id` is monotonic, so MAX(id) per wp_user_id is
        // the most recent snapshot for each distinct user.
        //
        // The previous implementation used a Postgres-only `DISTINCT ON`, which
        // returned the correct rows but broke the paginator's COUNT query: the
        // count ran against the base table WITHOUT the distinct, so `total`
        // reflected the number of raw snapshots (e.g. 5) instead of the number
        // of distinct users (e.g. 1) — the "5 users instead of 1" bug. The
        // id-subquery pattern (same as posts) both dedupes AND paginates with an
        // accurate total, and is portable across databases (see §7).
        $latestIds = $site->users()
            ->getQuery()
            ->select(DB::raw('MAX(id) as id'))
            ->groupBy('wp_user_id');

        $users = $site->users()
            ->whereIn('id', $latestIds)
            ->orderByDesc('snapshot_at')
            ->paginate($perPage);

        return response()->json([
            'data' => SiteUserResource::collection($users->items()),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                // Distinct user count for this site (deduplicated to the latest
                // snapshot per wp_user_id) — this is the number the "Total Users"
                // summary reflects.
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

        // Posts are stored as append-only snapshots: the same wp_post_id is
        // re-inserted on every collection run, so the raw table inflates over
        // time. We only ever want the LATEST snapshot per post. `id` is
        // monotonic, so MAX(id) per wp_post_id is the most recent snapshot —
        // and unlike Postgres-only DISTINCT ON, an id-subquery paginates
        // correctly (accurate total) and is portable across databases.
        $latestIds = $site->posts()
            ->getQuery()
            ->select(DB::raw('MAX(id) as id'))
            ->groupBy('wp_post_id');

        $query = $site->posts()
            ->whereIn('id', $latestIds)
            ->orderByDesc('post_date');

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
                'from' => $posts->firstItem(),
                'to' => $posts->lastItem(),
            ],
            // Site-wide content counts (deduplicated to the latest snapshot per
            // post), so the "Content Summary" cards reflect the whole site and
            // not just the current page.
            'summary' => $this->buildContentSummary($site),
        ]);
    }

    /**
     * Build deduplicated content counts for a site: total distinct posts plus
     * per-status counts, based on the latest snapshot of each wp_post_id.
     *
     * @return array<string, int>
     */
    private function buildContentSummary(Site $site): array
    {
        $latestIds = $site->posts()
            ->getQuery()
            ->select(DB::raw('MAX(id) as id'))
            ->groupBy('wp_post_id');

        $rows = $site->posts()
            ->whereIn('id', $latestIds)
            ->getQuery()
            ->select('post_status', DB::raw('COUNT(*) as c'))
            ->groupBy('post_status')
            ->pluck('c', 'post_status');

        $total = (int) $rows->sum();

        return [
            'total' => $total,
            'published' => (int) ($rows['publish'] ?? 0),
            'scheduled' => (int) ($rows['future'] ?? 0),
            'draft' => (int) ($rows['draft'] ?? 0),
        ];
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

        return response()->json([
            'data' => $this->buildUpdateStatusPayload($site),
        ]);
    }

    /**
     * Queue a remote "update this site now" command against a single site.
     *
     * The command is delivered to the connector on its next heartbeat (the
     * heartbeat controller flips pending -> dispatched and hands over the
     * command). The connector (v1.2.2+) runs the WordPress plugin upgrader and
     * reports back via the HMAC ack endpoint. Older connectors ignore the
     * command, so this is a safe no-op there — the UI warns before requesting.
     */
    public function requestUpdate(Request $request, string $uuid): JsonResponse
    {
        $site = $this->findSiteOrFail($request, $uuid);

        $type = $request->input('type', Site::UPDATE_CMD_TYPE_PLUGIN);
        if (! in_array($type, [
            Site::UPDATE_CMD_TYPE_PLUGIN,
            Site::UPDATE_CMD_TYPE_CORE,
            Site::UPDATE_CMD_TYPE_PLUGINS,
            Site::UPDATE_CMD_TYPE_THEMES,
        ], true)) {
            return response()->json(['message' => 'Unknown update type.'], 422);
        }

        if ($site->isRevoked()) {
            return response()->json([
                'message' => 'This site has been disconnected and cannot be updated remotely.',
            ], 422);
        }

        $current = $site->plugin_version;
        $targetVersion = null;

        if ($type === Site::UPDATE_CMD_TYPE_PLUGIN) {
            // Connector self-update: needs an active release that is newer.
            $active = \App\Models\PluginRelease::getActive();

            if (! $active) {
                return response()->json([
                    'message' => 'There is no active plugin release to update to.',
                ], 422);
            }

            $updateAvailable = $current
                ? version_compare($active->version, $current, '>')
                : true;

            if (! $updateAvailable) {
                return response()->json([
                    'message' => 'This site is already running the latest version.',
                ], 422);
            }

            $targetVersion = $active->version;
        } elseif ($type === Site::UPDATE_CMD_TYPE_THEMES) {
            // Bulk theme updates: requires connector 1.2.4+.
            if (! $site->supportsThemeUpdate()) {
                return response()->json([
                    'message' => 'This site\'s connector does not support remote theme '
                        . 'updates. Update the MarQira Connector to '
                        . Site::THEME_UPDATE_MIN_VERSION . ' or newer first.',
                ], 422);
            }

            // Reject when there is nothing to update (§1/§13).
            if ((int) $site->theme_updates_count < 1) {
                return response()->json([
                    'message' => 'All themes are up to date.',
                ], 422);
            }
        } else {
            // Core / all-plugins maintenance: requires connector 1.2.3+.
            if (! $site->supportsMaintenanceUpdate()) {
                return response()->json([
                    'message' => 'This site\'s connector does not support remote '
                        . ($type === Site::UPDATE_CMD_TYPE_CORE ? 'WordPress core' : 'plugin')
                        . ' updates. Update the MarQira Connector to '
                        . Site::MAINTENANCE_UPDATE_MIN_VERSION . ' or newer first.',
                ], 422);
            }

            // Reject when there is nothing to update (§1/§13).
            if ($type === Site::UPDATE_CMD_TYPE_CORE && ! $site->core_update_available) {
                return response()->json([
                    'message' => 'WordPress is up to date.',
                ], 422);
            }

            if ($type === Site::UPDATE_CMD_TYPE_PLUGINS && (int) $site->plugin_updates_count < 1) {
                return response()->json([
                    'message' => 'All plugins are up to date.',
                ], 422);
            }
        }

        // Guard against re-queuing while any command is already in flight.
        if (in_array($site->update_command_status, [
            Site::UPDATE_CMD_PENDING,
            Site::UPDATE_CMD_DISPATCHED,
            Site::UPDATE_CMD_IN_PROGRESS,
        ], true)) {
            return response()->json([
                'message' => 'An update is already in progress for this site.',
                'data' => $this->buildUpdateStatusPayload($site),
            ], 409);
        }

        $site->update([
            'update_command_status' => Site::UPDATE_CMD_PENDING,
            'update_command_type' => $type,
            'update_command_target_version' => $targetVersion,
            'update_command_requested_at' => now(),
            'update_command_requested_by' => $request->user()?->id,
            'update_command_dispatched_at' => null,
            'update_command_completed_at' => null,
            'update_command_message' => null,
        ]);

        AuditLog::record([
            'organization_id' => $site->organization_id,
            'actor_id' => $request->user()?->id,
            'actor_type' => 'user',
            'event' => 'site.update_requested',
            'subject_type' => 'site',
            'subject_id' => $site->id,
            'subject_uuid' => $site->uuid,
            'ip_address' => $request->ip(),
            'metadata' => [
                'domain' => $site->domain,
                'update_type' => $type,
                'target_version' => $targetVersion,
                'current_version' => $current,
                'remote_update_supported' => $site->supportsRemoteUpdate(),
            ],
        ]);

        $messages = [
            Site::UPDATE_CMD_TYPE_PLUGIN => 'Update requested. It will be delivered on the site\'s next heartbeat.',
            Site::UPDATE_CMD_TYPE_CORE => 'WordPress core update requested. It will be delivered on the site\'s next heartbeat.',
            Site::UPDATE_CMD_TYPE_PLUGINS => 'Plugin updates requested. They will be delivered on the site\'s next heartbeat.',
            Site::UPDATE_CMD_TYPE_THEMES => 'Theme updates requested. They will be delivered on the site\'s next heartbeat.',
        ];

        return response()->json([
            'message' => $messages[$type],
            'data' => $this->buildUpdateStatusPayload($site->fresh()),
        ]);
    }

    /**
     * Build the update-status payload for a site (shared by the read endpoint
     * and the request-update response so the UI always gets one shape).
     *
     * @return array<string, mixed>
     */
    private function buildUpdateStatusPayload(Site $site): array
    {
        $current = $site->plugin_version;
        $active  = \App\Models\PluginRelease::getActive();

        $command = [
            'status'         => $site->update_command_status,
            'type'           => $site->update_command_type,
            'target_version' => $site->update_command_target_version,
            'requested_at'   => $site->update_command_requested_at?->toIso8601String(),
            'dispatched_at'  => $site->update_command_dispatched_at?->toIso8601String(),
            'completed_at'   => $site->update_command_completed_at?->toIso8601String(),
            'message'        => $site->update_command_message,
        ];

        // Update inventory (§13) + per-type "can I queue this now?" flags. A
        // maintenance button is enabled only when an update of that type is
        // actually available, the connector supports it, and no command is
        // already in flight. This is the single source the UI keys off, and it
        // mirrors the backend enforcement in requestUpdate().
        $inFlight = in_array($site->update_command_status, [
            Site::UPDATE_CMD_PENDING,
            Site::UPDATE_CMD_DISPATCHED,
            Site::UPDATE_CMD_IN_PROGRESS,
        ], true);

        $inventory = [
            'core_update_available'   => (bool) $site->core_update_available,
            'plugin_updates_count'    => (int) $site->plugin_updates_count,
            'theme_updates_count'     => (int) $site->theme_updates_count,
            'updates_checked_at'      => $site->updates_checked_at?->toIso8601String(),
            'themes_update_supported' => $site->supportsThemeUpdate(),
            'command_in_flight'       => $inFlight,
            'can_update_core'         => $site->core_update_available
                && $site->supportsMaintenanceUpdate() && ! $inFlight,
            'can_update_plugins'      => (int) $site->plugin_updates_count > 0
                && $site->supportsMaintenanceUpdate() && ! $inFlight,
            'can_update_themes'       => (int) $site->theme_updates_count > 0
                && $site->supportsThemeUpdate() && ! $inFlight,
        ];

        // No active release published yet — nothing to compare against.
        if (! $active) {
            return array_merge([
                'current_version'         => $current,
                'latest_version'          => null,
                'update_available'        => false,
                'is_up_to_date'           => false,
                'has_active_release'      => false,
                'remote_update_supported' => $site->supportsRemoteUpdate(),
                'maintenance_update_supported' => $site->supportsMaintenanceUpdate(),
                'release'                 => null,
                'command'                 => $command,
            ], $inventory);
        }

        // A site with no reported version can't be compared reliably; treat as
        // "update available" so it surfaces for attention rather than hiding.
        $updateAvailable = $current
            ? version_compare($active->version, $current, '>')
            : true;

        return array_merge([
            'current_version'         => $current,
            'latest_version'          => $active->version,
            'update_available'        => $updateAvailable,
            'is_up_to_date'           => $current ? ! $updateAvailable : false,
            'has_active_release'      => true,
            'remote_update_supported' => $site->supportsRemoteUpdate(),
            'maintenance_update_supported' => $site->supportsMaintenanceUpdate(),
            'release'                 => [
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
            'command'                 => $command,
        ], $inventory);
    }

    /**
     * GET /api/dashboard/sites/{uuid}/visitors
     *
     * Fetch visitor analytics for a site (Phase 8). Returns daily metrics for
     * the last 30 days + growth percentage for chart rendering.
     */
    public function visitors(Request $request, string $uuid): JsonResponse
    {
        $site = $this->findSiteOrFail($request, $uuid);

        $days = (int) $request->query('days', 30);
        $days = max(7, min($days, 90)); // Clamp to 7–90 days.

        return response()->json([
            'daily_metrics' => VisitorAnalytics::getDailyMetrics($site, $days),
            'total_visitors' => VisitorAnalytics::getTotalVisitors($site, $days),
            'growth' => VisitorAnalytics::getGrowthPercentage($site),
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
