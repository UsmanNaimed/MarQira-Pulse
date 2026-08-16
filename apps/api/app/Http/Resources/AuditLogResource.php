<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'event' => $this->event,
            'actor_type' => $this->actor_type,
            'actor' => $this->whenLoaded('actor', fn () => $this->actor ? [
                'uuid' => $this->actor->uuid,
                'name' => $this->actor->name,
                'email' => $this->actor->email,
            ] : null),
            'subject_type' => $this->subject_type,
            'subject_uuid' => $this->subject_uuid,
            'ip_address' => $this->ip_address,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
