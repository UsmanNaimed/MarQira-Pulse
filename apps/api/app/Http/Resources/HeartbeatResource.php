<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single heartbeat record for the "Connection History" tab.
 */
class HeartbeatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'received_at' => $this->received_at?->toIso8601String(),
            'wp_version' => $this->wp_version,
            'php_version' => $this->php_version,
            'plugin_version' => $this->plugin_version,
            'server_ip' => $this->server_ip,
            'server_hostname' => $this->server_hostname,
            'origin_ip_candidate' => $this->origin_ip_candidate,
            'is_multisite' => (bool) $this->is_multisite,
        ];
    }
}
