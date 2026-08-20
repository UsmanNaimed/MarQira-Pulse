<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Concerns\ScopesToAccount;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Site;
use App\Services\Connector\ConnectorClient;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Dashboard → connector WordPress user management proxy (Phase C, §3–§13).
 *
 * Every method resolves the target site through the same tenant + account
 * authorization path as the rest of the dashboard (ScopesToAccount +
 * findSiteOrFail), then forwards a SIGNED request to the site's connector via
 * ConnectorClient::userAction(). The connector performs the actual WordPress
 * operation with core APIs and returns a structured result which we relay with
 * a meaningful HTTP status.
 *
 * Security (§11):
 *   - Authorization: only sites the caller can see are reachable (IDOR-safe).
 *   - Privilege escalation: assigning the WordPress `administrator` role
 *     requires an elevated MarQira account (platform owner or org owner). A
 *     lower-privileged member can never mint a site administrator.
 *   - Passwords are forwarded over TLS + HMAC and never logged or echoed back.
 */
class SiteUserController extends Controller
{
    use ScopesToAccount;

    public function __construct(
        private TenantContext $tenantContext,
        private ConnectorClient $connector,
    ) {}

    /**
     * GET /api/dashboard/sites/{uuid}/wp-users
     * List WordPress users (search, role filter, sorting, pagination).
     */
    public function index(Request $request, string $uuid): JsonResponse
    {
        $site = $this->findSiteOrFail($request, $uuid);
        if ($guard = $this->assertManageable($site)) {
            return $guard;
        }

        $validated = $request->validate([
            'page'     => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search'   => 'nullable|string|max:190',
            'role'     => 'nullable|string|max:100',
            'orderby'  => 'nullable|string|max:30',
            'order'    => 'nullable|in:asc,desc,ASC,DESC',
        ]);

        return $this->relay($this->connector->userAction($site, 'list', $validated));
    }

    /**
     * GET /api/dashboard/sites/{uuid}/wp-users/{id}
     */
    public function show(Request $request, string $uuid, int $id): JsonResponse
    {
        $site = $this->findSiteOrFail($request, $uuid);
        if ($guard = $this->assertManageable($site)) {
            return $guard;
        }

        return $this->relay($this->connector->userAction($site, 'get', ['id' => $id]));
    }

    /**
     * GET /api/dashboard/sites/{uuid}/wp-roles
     */
    public function roles(Request $request, string $uuid): JsonResponse
    {
        $site = $this->findSiteOrFail($request, $uuid);
        if ($guard = $this->assertManageable($site)) {
            return $guard;
        }

        return $this->relay($this->connector->userAction($site, 'roles', []));
    }

    /**
     * GET /api/dashboard/sites/{uuid}/wp-users/reassign-candidates?exclude={id}
     */
    public function reassignCandidates(Request $request, string $uuid): JsonResponse
    {
        $site = $this->findSiteOrFail($request, $uuid);
        if ($guard = $this->assertManageable($site)) {
            return $guard;
        }

        $validated = $request->validate([
            'exclude' => 'nullable|integer|min:1',
            'search'  => 'nullable|string|max:190',
        ]);

        return $this->relay($this->connector->userAction($site, 'reassign-candidates', $validated));
    }

    /**
     * POST /api/dashboard/sites/{uuid}/wp-users
     * Create a WordPress user (§4).
     */
    public function store(Request $request, string $uuid): JsonResponse
    {
        $site = $this->findSiteOrFail($request, $uuid);
        if ($guard = $this->assertManageable($site)) {
            return $guard;
        }

        $data = $this->validateCreate($request);

        if ($deny = $this->guardRole($request, $data['role'] ?? null)) {
            return $deny;
        }

        $result = $this->connector->userAction($site, 'create', $data);

        $this->audit($request, $site, 'site.wp_user_created', [
            'username' => $data['username'] ?? null,
            'role'     => $data['role'] ?? null,
            'ok'       => $result['ok'],
        ]);

        return $this->relay($result);
    }

    /**
     * PUT/PATCH /api/dashboard/sites/{uuid}/wp-users/{id}
     * Update a WordPress user (§5, §7).
     */
    public function update(Request $request, string $uuid, int $id): JsonResponse
    {
        $site = $this->findSiteOrFail($request, $uuid);
        if ($guard = $this->assertManageable($site)) {
            return $guard;
        }

        $data = $this->validateUpdate($request);
        $data['id'] = $id;

        if (array_key_exists('role', $data)) {
            if ($deny = $this->guardRole($request, $data['role'])) {
                return $deny;
            }
        }

        $result = $this->connector->userAction($site, 'update', $data);

        $this->audit($request, $site, 'site.wp_user_updated', [
            'target_user_id' => $id,
            'fields'         => array_keys($data),
            'ok'             => $result['ok'],
        ]);

        return $this->relay($result);
    }

    /**
     * DELETE /api/dashboard/sites/{uuid}/wp-users/{id}
     * Delete a WordPress user, with optional content reassignment (§6).
     */
    public function destroy(Request $request, string $uuid, int $id): JsonResponse
    {
        $site = $this->findSiteOrFail($request, $uuid);
        if ($guard = $this->assertManageable($site)) {
            return $guard;
        }

        $validated = $request->validate([
            'reassign_to'  => 'nullable|integer|min:1',
            'force_delete' => 'nullable|boolean',
        ]);

        $payload = ['id' => $id];
        if (! empty($validated['reassign_to'])) {
            $payload['reassign_to'] = (int) $validated['reassign_to'];
        }
        if (! empty($validated['force_delete'])) {
            $payload['force_delete'] = true;
        }

        $result = $this->connector->userAction($site, 'delete', $payload);

        $this->audit($request, $site, 'site.wp_user_deleted', [
            'target_user_id' => $id,
            'reassign_to'    => $payload['reassign_to'] ?? null,
            'ok'             => $result['ok'],
        ]);

        return $this->relay($result);
    }

    /**
     * POST /api/dashboard/wp-users/bulk-create
     * Create one WordPress user across MANY sites at once (§8, §9).
     *
     * Not all-or-nothing: each site returns its own result, and an
     * idempotency key derived from the operation id + site + username lets the
     * dashboard retry ONLY the failed sites without ever duplicating accounts.
     */
    public function bulkCreate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operation_id'       => 'nullable|string|max:64',
            'username'           => 'required|string|max:60',
            'email'              => 'required|email|max:190',
            'password'           => 'nullable|string|min:8|max:200',
            'first_name'         => 'nullable|string|max:100',
            'last_name'          => 'nullable|string|max:100',
            'display_name'       => 'nullable|string|max:150',
            'website'            => 'nullable|url|max:190',
            'bio'                => 'nullable|string|max:1000',
            'default_role'       => 'required|string|max:100',
            'sites'              => 'required|array|min:1',
            'sites.*.uuid'       => 'required|string',
            'sites.*.role'       => 'nullable|string|max:100',
        ]);

        // Stable operation id so retries dedupe across requests.
        $operationId = ($validated['operation_id'] ?? null) ?: (string) Str::uuid();

        $base = [
            'username'     => $validated['username'],
            'email'        => $validated['email'],
            'first_name'   => $validated['first_name'] ?? '',
            'last_name'    => $validated['last_name'] ?? '',
            'display_name' => $validated['display_name'] ?? '',
            'website'      => $validated['website'] ?? '',
            'bio'          => $validated['bio'] ?? '',
        ];
        if (! empty($validated['password'])) {
            $base['password'] = $validated['password'];
        }

        $results = [];
        foreach ($validated['sites'] as $entry) {
            $uuid = $entry['uuid'];
            $role = $entry['role'] ?? $validated['default_role'];

            $result = $this->bulkCreateOnSite($request, $uuid, $role, $base, $operationId);
            $results[] = $result;
        }

        $this->audit($request, null, 'site.wp_user_bulk_created', [
            'operation_id' => $operationId,
            'username'     => $validated['username'],
            'site_count'   => count($validated['sites']),
            'created'      => count(array_filter($results, fn ($r) => $r['status'] === 'created')),
            'failed'       => count(array_filter($results, fn ($r) => $r['status'] === 'failed')),
        ]);

        return response()->json([
            'operation_id' => $operationId,
            'results'      => $results,
        ]);
    }

    /**
     * Create the user on a single site as part of a bulk operation, returning a
     * per-site result row (§9). Never throws — every failure is captured.
     *
     * @param  array<string,mixed>  $base
     * @return array<string,mixed>
     */
    private function bulkCreateOnSite(Request $request, string $uuid, string $role, array $base, string $operationId): array
    {
        $row = [
            'uuid'    => $uuid,
            'domain'  => null,
            'role'    => $role,
            'status'  => 'failed',
            'message' => null,
        ];

        try {
            $site = $this->findSiteOrFail($request, $uuid);
        } catch (\Throwable $e) {
            $row['message'] = 'Website not found or not accessible.';
            return $row;
        }

        $row['domain'] = $site->domain;

        if ($site->isRevoked()) {
            $row['status'] = 'skipped';
            $row['message'] = 'Website is disconnected.';
            return $row;
        }
        if (! $site->supportsUserManagement()) {
            $row['status'] = 'skipped';
            $row['message'] = 'Connector too old (needs ' . Site::USER_MGMT_MIN_VERSION . '+).';
            return $row;
        }
        if ($deny = $this->guardRole($request, $role)) {
            $row['message'] = 'Not permitted to assign the administrator role.';
            return $row;
        }

        // Deterministic idempotency key: same operation + site + username never
        // creates twice, even across retries.
        $payload = array_merge($base, [
            'role'            => $role,
            'idempotency_key' => hash('sha256', $operationId . '|' . $uuid . '|' . $base['username']),
        ]);

        $result = $this->connector->userAction($site, 'create', $payload);

        if ($result['ok']) {
            $json = $result['json'];
            $row['status'] = ! empty($json['duplicate']) ? 'created' : 'created';
            $row['message'] = ! empty($json['duplicate'])
                ? 'Already created (idempotent).'
                : 'Created.';
            $row['user'] = $json['data'] ?? null;
        } else {
            $row['status'] = 'failed';
            $row['message'] = $result['error'] ?: 'Creation failed.';
        }

        return $row;
    }

    /* --------------------------------------------------------------------- */
    /* Helpers                                                              */
    /* --------------------------------------------------------------------- */

    /**
     * Validate create-user input (§4).
     *
     * @return array<string,mixed>
     */
    private function validateCreate(Request $request): array
    {
        $v = $request->validate([
            'username'     => 'required|string|max:60',
            'email'        => 'required|email|max:190',
            'password'     => 'nullable|string|min:8|max:200',
            'first_name'   => 'nullable|string|max:100',
            'last_name'    => 'nullable|string|max:100',
            'display_name' => 'nullable|string|max:150',
            'website'      => 'nullable|url|max:190',
            'bio'          => 'nullable|string|max:1000',
            'role'         => 'required|string|max:100',
            'meta'         => 'nullable|array',
        ]);

        return $v;
    }

    /**
     * Validate update-user input (§5). Only provided keys are forwarded so a
     * partial update never clobbers untouched fields.
     *
     * @return array<string,mixed>
     */
    private function validateUpdate(Request $request): array
    {
        $v = $request->validate([
            'email'        => 'sometimes|email|max:190',
            'password'     => 'sometimes|nullable|string|min:8|max:200',
            'first_name'   => 'sometimes|nullable|string|max:100',
            'last_name'    => 'sometimes|nullable|string|max:100',
            'display_name' => 'sometimes|nullable|string|max:150',
            'website'      => 'sometimes|nullable|url|max:190',
            'bio'          => 'sometimes|nullable|string|max:1000',
            'role'         => 'sometimes|string|max:100',
            'meta'         => 'sometimes|array',
        ]);

        // Drop an empty password so a blank field never changes the password.
        if (array_key_exists('password', $v) && ($v['password'] === null || $v['password'] === '')) {
            unset($v['password']);
        }

        return $v;
    }

    /**
     * Deny assigning the WordPress administrator role unless the MarQira account
     * is elevated (platform owner or org owner). Returns a 403 JsonResponse to
     * short-circuit, or null when allowed (§11 privilege escalation).
     */
    private function guardRole(Request $request, ?string $role): ?JsonResponse
    {
        if ($role !== 'administrator') {
            return null;
        }
        if ($this->canAssignAdministrator($request)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'error'   => 'forbidden_role',
            'message' => 'Your account is not permitted to assign the administrator role.',
        ], 403);
    }

    /**
     * Whether the caller may mint site administrators.
     */
    private function canAssignAdministrator(Request $request): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }
        if (method_exists($user, 'isOwner') && $user->isOwner()) {
            return true;
        }
        try {
            $org = $this->tenantContext->organization();
            return in_array($user->roleIn($org), ['owner'], true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Guard: the site must be connected and its connector must support user
     * management. Returns a JsonResponse to short-circuit, or null when OK.
     */
    private function assertManageable(Site $site): ?JsonResponse
    {
        if ($site->isRevoked()) {
            return response()->json([
                'success' => false,
                'error'   => 'site_revoked',
                'message' => 'This website has been disconnected and cannot be managed remotely.',
            ], 409);
        }
        if (! $site->supportsUserManagement()) {
            return response()->json([
                'success' => false,
                'error'   => 'connector_unsupported',
                'message' => 'This website\'s connector does not support user management. '
                    . 'Update the MarQira Connector to ' . Site::USER_MGMT_MIN_VERSION . ' or newer first.',
            ], 422);
        }

        return null;
    }

    /**
     * Translate a ConnectorClient result into a dashboard JSON response,
     * preserving the connector's status code and structured body.
     *
     * @param  array{ok:bool,status:int,json:array<string,mixed>,error:?string}  $result
     */
    private function relay(array $result): JsonResponse
    {
        $status = $result['status'] ?: ($result['ok'] ? 200 : 502);
        $body   = $result['json'];

        if (empty($body)) {
            $body = $result['ok']
                ? ['success' => true]
                : ['success' => false, 'message' => $result['error'] ?: 'The website could not be reached.'];
        }

        // Ensure a reachable-but-unhelpful body still carries an error message.
        if (! $result['ok'] && empty($body['message'])) {
            $body['message'] = $result['error'] ?: 'The request could not be completed.';
        }

        return response()->json($body, $status);
    }

    /**
     * Record an audit-log entry (§13). Never includes passwords.
     *
     * @param  array<string,mixed>  $metadata
     */
    private function audit(Request $request, ?Site $site, string $event, array $metadata): void
    {
        AuditLog::record([
            'organization_id' => $site?->organization_id ?? $this->safeOrgId(),
            'actor_id'        => $request->user()?->id,
            'actor_type'      => 'user',
            'event'           => $event,
            'subject_type'    => $site ? 'site' : null,
            'subject_id'      => $site?->id,
            'subject_uuid'    => $site?->uuid,
            'ip_address'      => $request->ip(),
            'metadata'        => $metadata,
        ]);
    }

    private function safeOrgId(): ?int
    {
        try {
            return $this->tenantContext->organizationId();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Resolve the target site through the tenant + account authorization path.
     */
    private function findSiteOrFail(Request $request, string $uuid): Site
    {
        return Site::query()
            ->where('organization_id', $this->tenantContext->organizationId())
            ->visibleTo($request->user())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }
}
