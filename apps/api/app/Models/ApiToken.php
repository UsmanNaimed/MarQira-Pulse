<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiToken extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'organization_id',
        'user_id',
        'created_by',
        'name',
        'token_hash',
        'abilities',
        'allowed_ips',
        'expires_at',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = [
        'id',
        'token_hash',
    ];

    protected $casts = [
        'abilities' => 'array',
        'allowed_ips' => 'array',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user this token authenticates AS. Every request made with the token
     * is authorized exactly as this user would be (§12/§13).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Resolve an active, non-expired, non-revoked token from a raw bearer
     * string, or null. Only the SHA-256 hash is ever compared/stored.
     */
    public static function findActiveByRawToken(string $rawToken): ?self
    {
        $token = static::query()
            ->where('token_hash', hash('sha256', $rawToken))
            ->first();

        return ($token && $token->isActive()) ? $token : null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    public function hasAbility(string $ability): bool
    {
        $abilities = $this->abilities ?? [];

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }
}
