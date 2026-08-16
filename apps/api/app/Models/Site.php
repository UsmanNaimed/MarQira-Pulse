<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use App\Services\Encryption\SecretEncryptor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    use HasFactory, HasUuidV7;

    protected $fillable = [
        'uuid',
        'organization_id',
        'domain',
        'home_url',
        'site_url',
        'status',
        'wp_version',
        'php_version',
        'plugin_version',
        'server_ip',
        'server_hostname',
        'server_software',
        'origin_ip',
        'origin_ip_source',
        'origin_ip_confidence',
        'origin_ip_verified',
        'origin_ip_verified_at',
        'origin_ip_verified_by',
        'is_multisite',
        'last_heartbeat_at',
        'last_seen_at',
        'enrolled_at',
        'disconnected_at',
    ];

    /**
     * Sequential id and the encrypted secret material are never serialized.
     */
    protected $hidden = [
        'id',
        'site_secret_encrypted',
        'site_secret_kid',
    ];

    protected $casts = [
        'origin_ip_verified' => 'boolean',
        'is_multisite' => 'boolean',
        'last_heartbeat_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'enrolled_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'origin_ip_verified_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function heartbeats(): HasMany
    {
        return $this->hasMany(SiteHeartbeat::class);
    }

    public function networkInfo(): HasMany
    {
        return $this->hasMany(SiteNetworkInfo::class);
    }

    public function isOnline(): bool
    {
        return $this->status === 'online';
    }

    /**
     * Encrypt a raw site secret at rest using AES-256-GCM and record the key id
     * used, so future key rotation can identify which key sealed the value.
     */
    public function encryptSecret(string $plaintext): void
    {
        $encryptor = app(SecretEncryptor::class);
        $this->site_secret_encrypted = $encryptor->encrypt($plaintext);
        $this->site_secret_kid = $encryptor->keyId();
    }

    /**
     * Decrypt and return the stored site secret, or null when none is stored.
     */
    public function decryptSecret(): ?string
    {
        if (empty($this->site_secret_encrypted)) {
            return null;
        }

        return app(SecretEncryptor::class)->decrypt($this->site_secret_encrypted);
    }
}
