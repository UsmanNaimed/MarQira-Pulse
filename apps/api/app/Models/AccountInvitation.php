<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use, expiring account setup/invitation token.
 *
 * Only the SHA-256 hash of the raw token is stored; the raw token lives only in
 * the setup URL that is emailed to the invitee. No plaintext password is ever
 * stored or emailed.
 */
class AccountInvitation extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'used_at',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isValid(): bool
    {
        return ! $this->isExpired() && ! $this->isUsed();
    }
}
