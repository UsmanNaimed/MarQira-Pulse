<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrollmentToken extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'organization_id',
        'token_hash',
        'expires_at',
        'used_at',
        'used_by_site_id',
        'created_by',
    ];

    protected $hidden = [
        'id',
        'token_hash',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function usedBySite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'used_by_site_id');
    }

    /**
     * Tokens that are neither used nor expired.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('used_at')
            ->where('expires_at', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}
