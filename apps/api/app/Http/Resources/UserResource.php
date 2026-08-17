<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $organization = $this->primaryOrganization();

        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            // Platform role drives which UI/actions the dashboard exposes. The
            // server always re-checks authorization, so this is a hint only.
            'platform_role' => $this->platform_role,
            'is_owner' => $this->isOwner(),
            'is_active' => $this->isActive(),
            'organization' => $organization ? [
                'uuid' => $organization->uuid,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'role' => $this->roleIn($organization),
            ] : null,
        ];
    }
}
