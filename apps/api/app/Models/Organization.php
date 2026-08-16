<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory, HasUuidV7;

    protected $fillable = [
        'name',
        'slug',
        'settings',
    ];

    /**
     * Never expose the sequential primary key externally.
     */
    protected $hidden = [
        'id',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    /**
     * Users that belong to this organization through the membership pivot.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_memberships')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function enrollmentTokens(): HasMany
    {
        return $this->hasMany(EnrollmentToken::class);
    }

    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }
}
