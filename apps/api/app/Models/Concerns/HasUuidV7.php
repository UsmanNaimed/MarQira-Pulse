<?php

namespace App\Models\Concerns;

use Ramsey\Uuid\Uuid;

/**
 * Auto-generates a UUID v7 for the model's `uuid` column on creation.
 *
 * UUID v7 is time-ordered, which keeps external identifiers sortable while
 * never exposing the sequential auto-increment primary key. Generation is
 * always performed in PHP via ramsey/uuid — never delegated to the database.
 */
trait HasUuidV7
{
    public static function bootHasUuidV7(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = Uuid::uuid7()->toString();
            }
        });
    }
}
