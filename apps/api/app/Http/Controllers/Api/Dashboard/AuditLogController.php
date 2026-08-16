<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only audit log view. Tenant-scoped and paginated.
 */
class AuditLogController extends Controller
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * GET /api/dashboard/audit-logs
     *
     * Optional filters: event (exact), subject_uuid (for a specific site).
     */
    public function index(Request $request): JsonResponse
    {
        $orgId = $this->tenantContext->organizationId();

        $query = AuditLog::query()
            ->where('organization_id', $orgId)
            ->with('actor:id,uuid,name,email')
            ->orderByDesc('created_at');

        if ($event = $request->query('event')) {
            $query->where('event', $event);
        }

        if ($subjectUuid = $request->query('subject_uuid')) {
            $query->where('subject_uuid', $subjectUuid);
        }

        $perPage = max(5, min((int) $request->query('per_page', 25), 100));

        $logs = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'data' => AuditLogResource::collection($logs->items()),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
