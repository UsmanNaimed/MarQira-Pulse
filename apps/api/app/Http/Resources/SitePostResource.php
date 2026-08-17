<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single site post snapshot for the "Content" tab.
 */
class SitePostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'snapshot_at' => $this->snapshot_at?->toIso8601String(),
            'wp_post_id' => $this->wp_post_id,
            'post_type' => $this->post_type,
            'post_status' => $this->post_status,
            'post_title' => $this->post_title,
            'post_date' => $this->post_date?->toIso8601String(),
            'post_modified' => $this->post_modified?->toIso8601String(),
            'post_author_id' => $this->post_author_id,
            'post_author_name' => $this->post_author_name,
            'guid' => $this->guid,
            'metadata' => $this->metadata,
        ];
    }
}
