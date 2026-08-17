<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * SiteUser — append-only snapshots of WordPress user data.
 *
 * Each row is a point-in-time capture of a user from a remote WordPress site.
 * Over time multiple snapshots of the same wp_user_id accumulate, allowing
 * historical tracking of role changes, login activity, etc.
 *
 * @property int $id
 * @property int $site_id
 * @property int $organization_id
 * @property \Carbon\Carbon|null $snapshot_at
 * @property int $wp_user_id
 * @property string $user_login
 * @property string|null $user_email
 * @property string|null $display_name
 * @property \Carbon\Carbon|null $user_registered
 * @property array|null $roles
 * @property \Carbon\Carbon|null $last_login_at
 * @property array|null $metadata
 * @property \Carbon\Carbon|null $created_at
 */
class SiteUser extends Model
{
    use HasFactory;

    /**
     * Append-only: no updates, so we don't track updated_at.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'site_id',
        'organization_id',
        'snapshot_at',
        'wp_user_id',
        'user_login',
        'user_email',
        'display_name',
        'user_registered',
        'roles',
        'last_login_at',
        'metadata',
        'created_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'snapshot_at' => 'datetime',
        'user_registered' => 'datetime',
        'last_login_at' => 'datetime',
        'roles' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Get the site this user snapshot belongs to.
     */
    public function site()
    {
        return $this->belongsTo(Site::class);
    }
}
