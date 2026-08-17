<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single site user snapshot for the "Users & Logins" tab.
 */
class SiteUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'snapshot_at' => $this->snapshot_at?->toIso8601String(),
            'wp_user_id' => $this->wp_user_id,
            'user_login' => $this->user_login,
            'user_email' => $this->user_email,
            'display_name' => $this->display_name,
            'user_registered' => $this->user_registered?->toIso8601String(),
            'roles' => $this->roles,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'last_login_ip' => $this->last_login_ip,
            'metadata' => $this->metadata,
        ];
    }
}
