<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily aggregated visitor and pageview metrics per site.
 *
 * Phase 8 — Visitor Analytics. Privacy-safe: no PII, no individual visits.
 * Connector sends daily totals (unique_visitors, pageviews) for analytics,
 * trend charts, and growth indicators.
 *
 * Append-only: metrics are never updated once recorded, only inserted.
 */
class SiteVisitorMetric extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'organization_id',
        'date',
        'unique_visitors',
        'pageviews',
        'recorded_at',
    ];

    protected $casts = [
        'date' => 'date',
        'recorded_at' => 'datetime',
        'created_at' => 'datetime',
        'unique_visitors' => 'integer',
        'pageviews' => 'integer',
    ];

    /**
     * The site this metric belongs to.
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * The organization this metric belongs to (denormalized for fast querying).
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Override save to set created_at on insert (append-only behavior).
     */
    public function save(array $options = [])
    {
        if (! $this->exists && ! $this->created_at) {
            $this->created_at = now();
        }

        return parent::save($options);
    }
}
