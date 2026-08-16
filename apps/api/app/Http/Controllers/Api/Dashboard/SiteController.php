<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\HeartbeatResource;
use App\Http\Resources\SiteDetailResource;
use App\Http\Resources\SiteResource;
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

        $query = Site::query()->where('organization_id', $orgId);

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
    public function show(string $uuid): JsonResponse
    {
        $site = $this->findSiteOrFail($uuid);

        return response()->json([
            'data' => new SiteDetailResource($site),
        ]);
    }

    /**
     * GET /api/dashboard/sites/{uuid}/heartbeats
     *
     * Connection history — most recent heartbeats first.
     */
    public function heartbeats(Request $request, string $uuid): JsonResponse
    {
        $site = $this->findSiteOrFail($uuid);

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
     * Look up a site by UUID within the current tenant, or 404.
     */
    private function findSiteOrFail(string $uuid): Site
    {
        $orgId = $this->tenantContext->organizationId();

        return Site::query()
            ->where('organization_id', $orgId)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }
}
