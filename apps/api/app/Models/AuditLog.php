<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit trail.
 *
 * Records may only ever be inserted. Any attempt to update an existing row or
 * to delete a row throws a LogicException — this is enforced at the model layer
 * so accidental mutation is impossible regardless of caller.
 */
class AuditLog extends Model
{
    use HasUuidV7;

    /**
     * Only created_at exists on the table; no updated_at.
     */
    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'organization_id',
        'actor_id',
        'actor_type',
        'api_token_id',
        'event',
        'subject_type',
        'subject_id',
        'subject_uuid',
        'ip_address',
        'user_agent',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (AuditLog $log) {
            if (empty($log->created_at)) {
                $log->created_at = now();
            }
        });

        // Fail closed against any update to an already-persisted record.
        static::updating(function () {
            throw new LogicException('Audit logs are append-only');
        });

        static::deleting(function () {
            throw new LogicException('Audit logs are append-only');
        });
    }

    /**
     * Block updates to existing records while still allowing initial inserts.
     */
    public function save(array $options = [])
    {
        if ($this->exists && $this->isDirty()) {
            throw new LogicException('Audit logs are append-only');
        }

        return parent::save($options);
    }

    /**
     * Deleting an audit record is never permitted.
     */
    public function delete()
    {
        throw new LogicException('Audit logs are append-only');
    }

    /**
     * The user who performed the action (when actor_type is "user").
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Convenience factory that records and persists a new audit entry.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function record(array $attributes): self
    {
        return static::create($attributes);
    }
}
