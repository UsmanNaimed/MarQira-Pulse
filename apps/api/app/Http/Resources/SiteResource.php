<?php

namespace App\Http\Resources;

use App\Services\SiteUptime;
use App\Services\VisitorAnalytics;
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
            // NOTE: server_ip is intentionally NOT exposed on the list resource
            // (§16). It consumed horizontal space and caused scrolling on the
            // Websites table; it remains available on SiteDetailResource under
            // the website's Network tab. server_hostname/software stay for the
            // origin context shown in the list.
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
            // Phase 8 — Visitor analytics (7-day totals & trend for overview/list).
            'visitors_7d' => VisitorAnalytics::getTotalVisitors($this->resource, 7),
            'visitors_trend_7d' => VisitorAnalytics::get7DayTrend($this->resource),
            'visitors_growth' => VisitorAnalytics::getGrowthPercentage($this->resource),
            // Per-site 7-day availability (uptime) — headline % + compact trend
            // for the Websites table sparkline. Derived from the site's own
            // heartbeats at hourly resolution; null/empty until it first reports.
            'uptime_7d_pct' => SiteUptime::averagePct($this->resource, 7),
            'uptime_trend_7d' => SiteUptime::trend($this->resource, 7),
            'last_heartbeat_at' => $this->last_heartbeat_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'enrolled_at' => $this->enrolled_at?->toIso8601String(),
            // Owning account (§14) — lets the Owner see which Subscriber a site
            // belongs to in the Websites list. Only populated when the relation
            // was eager-loaded; harmless (null) for a Subscriber's own list.
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'uuid' => $this->owner->uuid,
                'name' => $this->owner->name,
                'email' => $this->owner->email,
            ] : null),
        ];
    }
}
