<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * SitePost — append-only snapshots of WordPress post/page data.
 *
 * Each row is a point-in-time capture of a post/page from a remote WordPress
 * site. Over time multiple snapshots of the same wp_post_id accumulate,
 * allowing historical tracking of content changes, status transitions, etc.
 *
 * @property int $id
 * @property int $site_id
 * @property int $organization_id
 * @property \Carbon\Carbon|null $snapshot_at
 * @property int $wp_post_id
 * @property string $post_type
 * @property string|null $post_status
 * @property string|null $post_title
 * @property \Carbon\Carbon|null $post_date
 * @property \Carbon\Carbon|null $post_modified
 * @property int|null $post_author_id
 * @property string|null $post_author_name
 * @property string|null $guid
 * @property array|null $metadata
 * @property \Carbon\Carbon|null $created_at
 */
class SitePost extends Model
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
        'wp_post_id',
        'post_type',
        'post_status',
        'post_title',
        'post_date',
        'post_modified',
        'post_author_id',
        'post_author_name',
        'guid',
        'permalink',
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
        'post_date' => 'datetime',
        'post_modified' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Get the site this post snapshot belongs to.
     */
    public function site()
    {
        return $this->belongsTo(Site::class);
    }
}
