<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OriginIpHistoryResource;
use App\Models\AuditLog;
use App\Models\OriginIpHistory;
use App\Models\Site;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Site Origin IP Management Controller
 *
 * Handles manual verification and management of origin IPs for sites.
 */
class SiteOriginController extends Controller
{
    public function __construct(private TenantContext $tenantContext)
    {
    }

    /**
     * Get origin IP history for a site.
     *
     * GET /api/v1/sites/{uuid}/origin/history
     *
     * @param string $uuid Site UUID
     * @return \Illuminate\Http\JsonResponse
     */
    public function history(string $uuid)
    {
        $site = Site::where('uuid', $uuid)
            ->where('organization_id', $this->tenantContext->organizationId())
            ->firstOrFail();

        $history = OriginIpHistory::where('site_id', $site->id)
            ->orderBy('recorded_at', 'desc')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => OriginIpHistoryResource::collection($history),
        ]);
    }

    /**
     * Manually verify an origin IP.
     *
     * POST /api/v1/sites/{uuid}/origin/verify
     *
     * Body: { origin_ip, notes }
     *
     * @param Request $request
     * @param string $uuid Site UUID
     * @return \Illuminate\Http\JsonResponse
     */
    public function verify(Request $request, string $uuid)
    {
        $site = Site::where('uuid', $uuid)
            ->where('organization_id', $this->tenantContext->organizationId())
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'origin_ip' => 'required|ip',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        $originIp = $request->input('origin_ip');
        $notes = $request->input('notes');

        DB::beginTransaction();

        try {
            // Store previous state
            $previousOriginIp = $site->origin_ip;
            $previousConfidence = $site->origin_ip_confidence;

            // Update site with verified origin
            $site->update([
                'origin_ip' => $originIp,
                'origin_ip_source' => 'manual_verification',
                'origin_ip_confidence' => 'high', // Manual verification is always high confidence
                'origin_ip_verified' => true,
                'origin_ip_verified_at' => now(),
                'origin_ip_verified_by' => auth()->id(),
            ]);

            // Create history entry
            OriginIpHistory::create([
                'site_id' => $site->id,
                'organization_id' => $site->organization_id,
                'event_type' => 'verified',
                'origin_ip' => $originIp,
                'previous_origin_ip' => $previousOriginIp,
                'source' => 'manual_verification',
                'confidence' => 'high',
                'previous_confidence' => $previousConfidence,
                'verified' => true,
                'performed_by' => auth()->id(),
                'notes' => $notes,
                'metadata' => [
                    'user_id' => auth()->id(),
                    'user_email' => auth()->user()->email,
                ],
                'recorded_at' => now(),
            ]);

            // Audit log
            AuditLog::record(
                organization_id: $site->organization_id,
                actor_id: auth()->id(),
                event: 'site.origin_verified',
                subject_type: 'Site',
                subject_id: $site->id,
                subject_uuid: $site->uuid,
                metadata: [
                    'origin_ip' => $originIp,
                    'previous_origin_ip' => $previousOriginIp,
                    'domain' => $site->domain,
                    'notes' => $notes,
                ]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Origin IP verified successfully',
                'data' => [
                    'origin_ip' => $site->origin_ip,
                    'confidence' => $site->origin_ip_confidence,
                    'verified' => $site->origin_ip_verified,
                    'verified_at' => $site->origin_ip_verified_at?->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);

            return response()->json([
                'error' => 'Verification failed',
                'message' => 'An error occurred while verifying the origin IP.',
            ], 500);
        }
    }

    /**
     * Update origin confidence level manually.
     *
     * PATCH /api/v1/sites/{uuid}/origin/confidence
     *
     * Body: { confidence, notes }
     * confidence: high|medium|low|unknown
     *
     * @param Request $request
     * @param string $uuid Site UUID
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateConfidence(Request $request, string $uuid)
    {
        $site = Site::where('uuid', $uuid)
            ->where('organization_id', $this->tenantContext->organizationId())
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'confidence' => 'required|in:high,medium,low,unknown',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        $confidence = $request->input('confidence');
        $notes = $request->input('notes');

        DB::beginTransaction();

        try {
            $previousConfidence = $site->origin_ip_confidence;

            if ($previousConfidence === $confidence) {
                return response()->json([
                    'success' => true,
                    'message' => 'Confidence already set to this level',
                ]);
            }

            // Update site
            $site->update([
                'origin_ip_confidence' => $confidence,
            ]);

            // Create history entry
            OriginIpHistory::create([
                'site_id' => $site->id,
                'organization_id' => $site->organization_id,
                'event_type' => 'confidence_changed',
                'origin_ip' => $site->origin_ip,
                'source' => 'manual_adjustment',
                'confidence' => $confidence,
                'previous_confidence' => $previousConfidence,
                'verified' => false,
                'performed_by' => auth()->id(),
                'notes' => $notes,
                'metadata' => [
                    'user_id' => auth()->id(),
                    'user_email' => auth()->user()->email,
                ],
                'recorded_at' => now(),
            ]);

            // Audit log
            AuditLog::record(
                organization_id: $site->organization_id,
                actor_id: auth()->id(),
                event: 'site.origin_confidence_changed',
                subject_type: 'Site',
                subject_id: $site->id,
                subject_uuid: $site->uuid,
                metadata: [
                    'origin_ip' => $site->origin_ip,
                    'confidence' => $confidence,
                    'previous_confidence' => $previousConfidence,
                    'domain' => $site->domain,
                    'notes' => $notes,
                ]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Origin IP confidence updated successfully',
                'data' => [
                    'confidence' => $site->origin_ip_confidence,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);

            return response()->json([
                'error' => 'Update failed',
                'message' => 'An error occurred while updating confidence.',
            ], 500);
        }
    }
}
