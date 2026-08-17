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

    /** Lifecycle statuses. */
    public const STATUS_ONLINE = 'online';
    public const STATUS_OFFLINE = 'offline';
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_REVOKED = 'revoked';

    /** Remote update-command lifecycle. */
    public const UPDATE_CMD_PENDING = 'pending';
    public const UPDATE_CMD_DISPATCHED = 'dispatched';
    public const UPDATE_CMD_IN_PROGRESS = 'in_progress';
    public const UPDATE_CMD_COMPLETED = 'completed';
    public const UPDATE_CMD_FAILED = 'failed';

    /**
     * Minimum connector version that understands the heartbeat update command
     * channel. Sites reporting an older version can only be updated manually.
     */
    public const REMOTE_UPDATE_MIN_VERSION = '1.2.2';

    protected $fillable = [
        'uuid',
        'organization_id',
        'owner_user_id',
        'domain',
        'domain_normalized',
        'home_url',
        'site_url',
        'status',
        'site_secret_encrypted',
        'site_secret_kid',
        'wp_version',
        'php_version',
        'plugin_version',
        'server_ip',
        'server_hostname',
        'server_software',
        'update_command_status',
        'update_command_target_version',
        'update_command_requested_at',
        'update_command_requested_by',
        'update_command_dispatched_at',
        'update_command_completed_at',
        'update_command_message',
        'origin_ip',
        'origin_ip_source',
        'origin_ip_confidence',
        'origin_ip_verified',
        'origin_ip_verified_at',
        'origin_ip_verified_by',
        'is_multisite',
        'last_heartbeat_at',
        'last_seen_at',
        'offline_since',
        'last_offline_alert_at',
        'offline_alert_count',
        'enrolled_at',
        'disconnected_at',
        'revoked_at',
        'revoked_by',
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
        'offline_since' => 'datetime',
        'last_offline_alert_at' => 'datetime',
        'offline_alert_count' => 'integer',
        'enrolled_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'revoked_at' => 'datetime',
        'origin_ip_verified_at' => 'datetime',
        'update_command_requested_at' => 'datetime',
        'update_command_dispatched_at' => 'datetime',
        'update_command_completed_at' => 'datetime',
    ];

    /**
     * Whether the site's reported connector version supports the remote update
     * command channel (heartbeat-delivered "update now"). Null/older versions
     * can only be updated manually (WP admin or `wp marqira update`).
     */
    public function supportsRemoteUpdate(): bool
    {
        return $this->plugin_version
            && version_compare($this->plugin_version, self::REMOTE_UPDATE_MIN_VERSION, '>=');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The Subscriber (or Owner) who owns/added this website.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function heartbeats(): HasMany
    {
        return $this->hasMany(SiteHeartbeat::class);
    }

    public function networkInfo(): HasMany
    {
        return $this->hasMany(SiteNetworkInfo::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(SiteUser::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(SitePost::class);
    }

    public function originIpHistory(): HasMany
    {
        return $this->hasMany(OriginIpHistory::class);
    }

    public function isOnline(): bool
    {
        return $this->status === self::STATUS_ONLINE;
    }

    /**
     * A revoked site's credentials are dead. The connector is told to
     * self-disconnect and the record is hidden from active dashboard lists.
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null || $this->status === self::STATUS_REVOKED;
    }

    /**
     * Only sites that have not been revoked.
     */
    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * Restrict a query to the websites a given user is allowed to see.
     *
     * Server-side tenant authorization (see §2/§30): the Owner sees every site
     * on the platform; a Subscriber sees only sites they own. Frontend hiding
     * is never relied upon — this scope is the enforcement point.
     */
    public function scopeVisibleTo($query, User $user)
    {
        if ($user->isOwner()) {
            return $query;
        }

        return $query->where('owner_user_id', $user->id);
    }

    /**
     * Normalize a domain/URL to a bare, comparable host: lowercase, no scheme,
     * no "www.", no path/port. Used for duplicate-site prevention.
     */
    public static function normalizeDomain(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(strtolower($value));
        if ($value === '') {
            return null;
        }

        if (str_contains($value, '://')) {
            $value = (string) parse_url($value, PHP_URL_HOST);
        }

        $value = explode('/', $value)[0];
        $value = explode(':', $value)[0];
        $value = preg_replace('/^www\./', '', $value);

        return $value !== '' ? $value : null;
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
