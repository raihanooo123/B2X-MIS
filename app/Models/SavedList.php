<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 02 §14.4, §31.3 — a buying list shared by everyone on a company's
 * account (05.1 §7.3): SKU, pack and quantity intent only — never a price
 * or cost. `version` guards concurrent edits (409 on a stale write).
 * Written by App\Domain\Ordering\BulkEntry\SavedLists only.
 *
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property string $name
 * @property int|null $created_by_user_id
 * @property string $source manual | cart | order
 * @property int|null $source_order_id
 * @property int $version
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $createdBy
 * @property-read int|null $lines_count
 */
class SavedList extends Model
{
    use HasPublicId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    /** @return HasMany<SavedListLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SavedListLine::class)->orderBy('position')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
