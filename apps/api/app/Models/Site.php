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
    public const UPDATE_CMD_QUEUED = 'queued';         // accepted by the site's push endpoint
    public const UPDATE_CMD_DISPATCHED = 'dispatched'; // handed over (heartbeat or push)
    public const UPDATE_CMD_STARTING = 'starting';
    public const UPDATE_CMD_DOWNLOADING = 'downloading';
    public const UPDATE_CMD_INSTALLING = 'installing';
    public const UPDATE_CMD_IN_PROGRESS = 'in_progress';
    public const UPDATE_CMD_VERIFYING = 'verifying';
    public const UPDATE_CMD_COMPLETED = 'completed';
    public const UPDATE_CMD_FAILED = 'failed';
    public const UPDATE_CMD_ROLLED_BACK = 'rolled_back';

    /**
     * Statuses that mean a command is still in flight (occupying the single
     * update slot). The dashboard shows live progress for these and the API
     * refuses to queue a second command while any of them is set.
     *
     * @var array<int, string>
     */
    public const UPDATE_CMD_IN_FLIGHT = [
        self::UPDATE_CMD_PENDING,
        self::UPDATE_CMD_QUEUED,
        self::UPDATE_CMD_DISPATCHED,
        self::UPDATE_CMD_STARTING,
        self::UPDATE_CMD_DOWNLOADING,
        self::UPDATE_CMD_INSTALLING,
        self::UPDATE_CMD_IN_PROGRESS,
        self::UPDATE_CMD_VERIFYING,
    ];

    /**
     * Terminal statuses — the command has resolved one way or another.
     *
     * @var array<int, string>
     */
    public const UPDATE_CMD_TERMINAL = [
        self::UPDATE_CMD_COMPLETED,
        self::UPDATE_CMD_FAILED,
        self::UPDATE_CMD_ROLLED_BACK,
    ];

    /**
     * How long (minutes) a command may stay in flight with no further progress
     * before it is treated as stalled and force-failed, so the single update
     * slot is released and the operator can retry. Covers a connector process
     * that died mid-upgrade (fatal/OOM/timeout) and never sent a final ack.
     */
    public const UPDATE_CMD_STALE_MINUTES = 20;

    /** What a queued update command targets. */
    public const UPDATE_CMD_TYPE_PLUGIN = 'plugin';   // connector self-update
    public const UPDATE_CMD_TYPE_CORE = 'core';       // WordPress core upgrade
    public const UPDATE_CMD_TYPE_PLUGINS = 'plugins'; // bulk-update all plugins
    public const UPDATE_CMD_TYPE_THEMES = 'themes';   // bulk-update all themes

    /**
     * Minimum connector version that understands the heartbeat update command
     * channel. Sites reporting an older version can only be updated manually.
     */
    public const REMOTE_UPDATE_MIN_VERSION = '1.2.2';

    /**
     * Minimum connector version that understands core / bulk-plugin update
     * commands (added in 1.2.3). Plugin self-update works from 1.2.2.
     */
    public const MAINTENANCE_UPDATE_MIN_VERSION = '1.2.3';

    /**
     * Minimum connector version that understands bulk-theme update commands and
     * reports the update inventory (core/plugins/themes counts) on heartbeat.
     * Added in 1.2.4.
     */
    public const THEME_UPDATE_MIN_VERSION = '1.2.4';

    /**
     * Minimum connector version that exposes the signed control-plane REST push
     * endpoint (marqira/v1/execute-update), enabling an update to start the
     * instant it is requested instead of waiting for the next heartbeat. Added
     * in 1.2.10. Older connectors still receive the command via heartbeat.
     */
    public const PUSH_UPDATE_MIN_VERSION = '1.2.10';

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
        'update_command_type',
        'update_command_id',
        'update_command_target_version',
        'update_command_requested_at',
        'update_command_requested_by',
        'update_command_dispatched_at',
        'update_command_completed_at',
        'update_command_message',
        'update_command_recovery',
        'origin_ip',
        'origin_ip_source',
        'origin_ip_confidence',
        'origin_ip_verified',
        'origin_ip_verified_at',
        'origin_ip_verified_by',
        'is_multisite',
        'core_update_available',
        'plugin_updates_count',
        'theme_updates_count',
        'updates_checked_at',
        'last_heartbeat_at',
        'last_seen_at',
        'offline_since',
        'last_offline_alert_at',
        'offline_alert_count',
        'consecutive_check_failures',
        'consecutive_check_successes',
        'last_active_check_at',
        'last_active_check_status',
        'last_active_check_reason',
        'last_active_check_http_code',
        'last_active_check_latency_ms',
        'enrolled_at',
        'uptime_reset_at',
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
        'core_update_available' => 'boolean',
        'plugin_updates_count' => 'integer',
        'theme_updates_count' => 'integer',
        'updates_checked_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'offline_since' => 'datetime',
        'last_offline_alert_at' => 'datetime',
        'offline_alert_count' => 'integer',
        'consecutive_check_failures' => 'integer',
        'consecutive_check_successes' => 'integer',
        'last_active_check_at' => 'datetime',
        'last_active_check_http_code' => 'integer',
        'last_active_check_latency_ms' => 'integer',
        'enrolled_at' => 'datetime',
        'uptime_reset_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'revoked_at' => 'datetime',
        'origin_ip_verified_at' => 'datetime',
        'update_command_requested_at' => 'datetime',
        'update_command_dispatched_at' => 'datetime',
        'update_command_completed_at' => 'datetime',
        'update_command_recovery' => 'array',
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

    /**
     * Whether the site's connector supports remote WordPress-core and
     * bulk-plugin update commands (connector 1.2.3+).
     */
    public function supportsMaintenanceUpdate(): bool
    {
        return $this->plugin_version
            && version_compare($this->plugin_version, self::MAINTENANCE_UPDATE_MIN_VERSION, '>=');
    }

    /**
     * Whether the site's connector supports remote bulk-theme updates and
     * reports the update inventory on heartbeat (connector 1.2.4+).
     */
    public function supportsThemeUpdate(): bool
    {
        return $this->plugin_version
            && version_compare($this->plugin_version, self::THEME_UPDATE_MIN_VERSION, '>=');
    }

    /**
     * Whether the site's connector supports the API->site "push" delivery
     * channel (connector 1.2.10+), where the dashboard signs and POSTs the
     * update command straight to the site's REST endpoint so execution starts
     * immediately instead of waiting for the next WP-Cron heartbeat.
     */
    public function supportsPushUpdate(): bool
    {
        return $this->plugin_version
            && version_compare($this->plugin_version, self::PUSH_UPDATE_MIN_VERSION, '>=');
    }

    /**
     * Whether an update command is currently in flight (queued/dispatched/
     * running and not yet terminal). Used to guard against duplicate requests
     * and to drive the live status UI.
     */
    public function isUpdateInFlight(): bool
    {
        return in_array($this->update_command_status, self::UPDATE_CMD_IN_FLIGHT, true);
    }

    /**
     * Fail-safe recovery for stuck commands. If a command has been in flight
     * longer than UPDATE_CMD_STALE_MINUTES with no terminal ack from the site,
     * mark it failed with an actionable message so the UI never hangs forever
     * and the user can retry. No-op if not in flight or not yet stale.
     */
    public function reconcileStaleUpdateCommand(): void
    {
        if (! $this->isUpdateInFlight()) {
            return;
        }

        $startedAt = $this->update_command_dispatched_at ?? $this->update_command_requested_at;
        if (! $startedAt) {
            return;
        }

        if ($startedAt->copy()->addMinutes(self::UPDATE_CMD_STALE_MINUTES)->isFuture()) {
            return;
        }

        $this->forceFill([
            'update_command_status' => self::UPDATE_CMD_FAILED,
            'update_command_message' => 'Update timed out; no response from the site. You can retry.',
            'update_command_completed_at' => now(),
        ])->save();
    }

    /**
     * Whether this site has ANY pending update (core, plugins, or themes) as of
     * its last reported inventory. This is the single source of truth (§13) for
     * both the Updates tab and the Websites overview warning.
     */
    public function hasUpdatesAvailable(): bool
    {
        return (bool) $this->core_update_available
            || (int) $this->plugin_updates_count > 0
            || (int) $this->theme_updates_count > 0;
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

    /**
     * Daily visitor metrics for this site (Phase 8 — analytics).
     */
    public function visitorMetrics(): HasMany
    {
        return $this->hasMany(SiteVisitorMetric::class);
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
