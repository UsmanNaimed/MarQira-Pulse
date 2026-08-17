<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Origin IP History Model
 *
 * Tracks all changes to a site's origin IP, including automatic detections,
 * manual verifications, and confidence level changes.
 *
 * @property int $id
 * @property int $site_id
 * @property int $organization_id
 * @property string $event_type
 * @property string|null $origin_ip
 * @property string|null $previous_origin_ip
 * @property string|null $source
 * @property string|null $confidence
 * @property string|null $previous_confidence
 * @property bool $verified
 * @property int|null $performed_by
 * @property array|null $metadata
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon $recorded_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class OriginIpHistory extends Model
{
    /**
     * Disable updated_at (append-only table).
     */
    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'site_id',
        'organization_id',
        'event_type',
        'origin_ip',
        'previous_origin_ip',
        'source',
        'confidence',
        'previous_confidence',
        'verified',
        'performed_by',
        'metadata',
        'notes',
        'recorded_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'verified' => 'boolean',
        'metadata' => 'array',
        'recorded_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Get the site that owns this history entry.
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Get the user who performed this action (for manual verifications).
     */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * Boot the model (ensure created_at is set).
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (OriginIpHistory $history) {
            if (! $history->created_at) {
                $history->created_at = now();
            }
            if (! $history->recorded_at) {
                $history->recorded_at = now();
            }
        });
    }
}
