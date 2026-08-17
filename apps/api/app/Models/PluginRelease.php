<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plugin Release Model
 *
 * Stores MarQira Connector plugin versions for the private update server.
 * Only one release can be marked as "active" (the current stable version).
 *
 * @property int $id
 * @property string $version
 * @property string|null $changelog
 * @property string $download_url
 * @property string|null $file_hash
 * @property int|null $file_size
 * @property string|null $requires_wp
 * @property string|null $requires_php
 * @property string|null $tested_up_to
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $released_at
 * @property int|null $released_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class PluginRelease extends Model
{
    protected $fillable = [
        'version',
        'changelog',
        'download_url',
        'storage_path',
        'file_hash',
        'file_size',
        'requires_wp',
        'requires_php',
        'tested_up_to',
        'is_active',
        'released_at',
        'released_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'file_size' => 'integer',
        'released_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the user who released this version.
     */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    /**
     * Scope to get only active releases.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the currently active plugin release.
     */
    public static function getActive(): ?self
    {
        return static::where('is_active', true)->first();
    }

    /**
     * Mark this release as active and deactivate all others.
     */
    public function activate(): void
    {
        \DB::transaction(function () {
            // Deactivate all other releases
            static::where('id', '!=', $this->id)->update(['is_active' => false]);
            
            // Activate this one
            $this->update(['is_active' => true]);
        });
    }
}
