<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteNetworkInfo extends Model
{
    protected $table = 'site_network_info';

    /**
     * Append-only record; only created_at is tracked.
     */
    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'organization_id',
        'recorded_at',
        'network_sites_count',
        'network_data',
        'created_at',
    ];

    protected $casts = [
        'network_data' => 'array',
        'network_sites_count' => 'integer',
        'recorded_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (SiteNetworkInfo $info) {
            if (empty($info->created_at)) {
                $info->created_at = now();
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
