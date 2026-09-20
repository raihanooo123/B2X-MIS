<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Doc 02 §2.1 — external identifier: a ULID, never the auto-increment id,
 * on any entity exposed in a URL or API payload.
 */
trait HasPublicId
{
    protected static function bootHasPublicId(): void
    {
        static::creating(function ($model): void {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::ulid();
            }
        });
    }
}
