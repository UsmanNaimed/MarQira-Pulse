<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, HasUuidV7, Notifiable;

    /** Platform-level roles (distinct from per-organization membership role). */
    public const ROLE_OWNER = 'owner';
    public const ROLE_SUBSCRIBER = 'subscriber';

    protected $fillable = [
        'name',
        'email',
        'password',
        'platform_role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'id',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The platform Owner has full cross-platform visibility and administration.
     */
    public function isOwner(): bool
    {
        return $this->platform_role === self::ROLE_OWNER;
    }

    /**
     * A Subscriber may only ever act on the websites they own.
     */
    public function isSubscriber(): bool
    {
        return ! $this->isOwner();
    }

    /**
     * Deactivated accounts are blocked from authenticating.
     */
    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    /**
     * Websites this user owns (added/enrolled). The Owner still sees every
     * site regardless of this relationship; ownership drives Subscriber
     * isolation.
     */
    public function ownedSites(): HasMany
    {
        return $this->hasMany(Site::class, 'owner_user_id');
    }

    /**
     * Organizations this user belongs to, with the role carried on the pivot.
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_memberships')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Direct membership rows for this user.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /**
     * Resolve the tenant organization for this user.
     *
     * The dashboard is single-organization per user for now; we prefer an
     * "owner" membership and otherwise fall back to the earliest membership.
     * Returns null when the user belongs to no organization (fail closed at
     * the call site).
     */
    public function primaryOrganization(): ?Organization
    {
        return $this->organizations()
            ->orderByRaw("CASE WHEN organization_memberships.role = 'owner' THEN 0 ELSE 1 END")
            ->orderBy('organization_memberships.created_at')
            ->first();
    }

    /**
     * The user's role within the given organization, or null if not a member.
     */
    public function roleIn(Organization $organization): ?string
    {
        $membership = $this->memberships()
            ->where('organization_id', $organization->id)
            ->first();

        return $membership?->role;
    }
}
