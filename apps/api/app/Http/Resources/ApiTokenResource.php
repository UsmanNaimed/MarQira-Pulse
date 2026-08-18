<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representation of an API token. The raw token value is NEVER included here —
 * it is only returned once, at creation time, by the controller.
 */
class ApiTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'abilities' => $this->abilities ?? [],
            'allowed_ips' => $this->allowed_ips ?? [],
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'is_active' => $this->isActive(),
            'created_at' => $this->created_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('createdByUser', fn () => $this->createdByUser ? [
                'uuid' => $this->createdByUser->uuid,
                'name' => $this->createdByUser->name,
            ] : null),
            // The user this token authenticates AS — the token's data scope is
            // exactly this user's authorized websites (§12/§13).
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'uuid' => $this->user->uuid,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null),
        ];
    }
}
