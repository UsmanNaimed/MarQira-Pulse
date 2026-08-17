<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OriginIpHistoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type,
            'origin_ip' => $this->origin_ip,
            'previous_origin_ip' => $this->previous_origin_ip,
            'source' => $this->source,
            'confidence' => $this->confidence,
            'previous_confidence' => $this->previous_confidence,
            'verified' => $this->verified,
            'performed_by' => $this->when($this->performed_by, [
                'id' => $this->performedBy?->id,
                'name' => $this->performedBy?->name,
                'email' => $this->performedBy?->email,
            ]),
            'metadata' => $this->metadata,
            'notes' => $this->notes,
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
