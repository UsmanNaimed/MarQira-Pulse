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
            'organization' => $organization ? [
                'uuid' => $organization->uuid,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'role' => $this->roleIn($organization),
            ] : null,
        ];
    }
}
