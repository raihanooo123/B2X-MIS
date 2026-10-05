<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 05.6 §7A.11, §7A.13 — pay at collection withdrawn from a customer: a
 * public customer (`user_id`) or a trade company (`company_id`), never
 * both. At most one active row per customer; a lift sets `lifted_*` and
 * is never deleted, and the next suspension is a new row.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $company_id
 * @property string $reason no_shows | manual
 * @property int|null $no_show_count
 * @property Carbon $suspended_at
 * @property int|null $suspended_by_user_id
 * @property string|null $note
 * @property Carbon|null $lifted_at
 * @property int|null $lifted_by_user_id
 * @property string|null $lift_reason
 */
class PayAtCollectionSuspension extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'company_id', 'reason', 'no_show_count', 'suspended_at', 'suspended_by_user_id',
        'note', 'lifted_at', 'lifted_by_user_id', 'lift_reason',
    ];

    protected function casts(): array
    {
        return ['suspended_at' => 'datetime', 'lifted_at' => 'datetime', 'no_show_count' => 'integer'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
