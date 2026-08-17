<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Summary representation of a site, used for the Websites table.
 *
 * Never exposes the sequential id or the encrypted secret material (those are
 * $hidden on the model, but we build the array explicitly here for clarity).
 */
class SiteResource extends JsonResource
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
            'server_ip' => $this->server_ip,
            'server_hostname' => $this->server_hostname,
            'server_software' => $this->server_software,
            'origin_ip' => $this->origin_ip,
            'origin_ip_source' => $this->origin_ip_source,
            'origin_ip_confidence' => $this->origin_ip_confidence,
            'origin_ip_verified' => (bool) $this->origin_ip_verified,
            'origin_ip_verified_at' => $this->origin_ip_verified_at?->toIso8601String(),
            'wp_version' => $this->wp_version,
            'php_version' => $this->php_version,
            'plugin_version' => $this->plugin_version,
            'is_multisite' => (bool) $this->is_multisite,
            // Lightweight update-inventory summary (§3/§13) for the Websites
            // overview "Updates available" indicator. Derived from the site's
            // last reported inventory — the same source the Updates tab uses.
            'has_updates' => (bool) $this->hasUpdatesAvailable(),
            'core_updates_available' => (bool) $this->core_update_available,
            'plugin_updates_available' => (int) $this->plugin_updates_count,
            'theme_updates_available' => (int) $this->theme_updates_count,
            'last_heartbeat_at' => $this->last_heartbeat_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'enrolled_at' => $this->enrolled_at?->toIso8601String(),
        ];
    }
}
