<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representation of an enrollment token (connection code). The raw code is only
 * returned once at creation; here we only expose status metadata.
 */
class EnrollmentTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'created_at' => $this->created_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'is_expired' => $this->isExpired(),
            'is_used' => $this->isUsed(),
            'used_at' => $this->used_at?->toIso8601String(),
            'used_by_site' => $this->whenLoaded('usedBySite', fn () => $this->usedBySite ? [
                'uuid' => $this->usedBySite->uuid,
                'domain' => $this->usedBySite->domain,
            ] : null),
        ];
    }
}
