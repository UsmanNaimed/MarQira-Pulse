<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full representation of a single site for the website detail view.
 */
class SiteDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'domain' => $this->domain,
            'home_url' => $this->home_url,
            'site_url' => $this->site_url,
            'status' => $this->status,

            // Network / origin
            'server_ip' => $this->server_ip,
            'server_hostname' => $this->server_hostname,
            'server_software' => $this->server_software,
            'origin_ip' => $this->origin_ip,
            'origin_ip_source' => $this->origin_ip_source,
            'origin_ip_confidence' => $this->origin_ip_confidence,
            'origin_ip_verified' => (bool) $this->origin_ip_verified,
            'origin_ip_verified_at' => $this->origin_ip_verified_at?->toIso8601String(),
            'origin_ip_verified_by' => $this->origin_ip_verified_by,

            // WordPress / runtime
            'wp_version' => $this->wp_version,
            'php_version' => $this->php_version,
            'plugin_version' => $this->plugin_version,
            'is_multisite' => (bool) $this->is_multisite,

            // Timeline
            'last_heartbeat_at' => $this->last_heartbeat_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'enrolled_at' => $this->enrolled_at?->toIso8601String(),
            'disconnected_at' => $this->disconnected_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
