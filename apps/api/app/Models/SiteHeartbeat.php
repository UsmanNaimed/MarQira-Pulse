<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteHeartbeat extends Model
{
    /**
     * Heartbeats are append-only telemetry; only created_at is tracked.
     */
    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'organization_id',
        'received_at',
        'wp_version',
        'php_version',
        'plugin_version',
        'server_ip',
        'server_hostname',
        'server_software',
        'origin_ip_candidate',
        'is_multisite',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'is_multisite' => 'boolean',
        'received_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (SiteHeartbeat $heartbeat) {
            if (empty($heartbeat->created_at)) {
                $heartbeat->created_at = now();
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
