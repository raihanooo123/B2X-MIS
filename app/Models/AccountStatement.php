<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 02 §31.2 — a fixed as-of statement request: the UK calendar range and the
 * cutoff instant captured when it was asked for. Its figures live in the
 * statement's DocumentRender payload, so later postings never change it.
 * Immutable (database guard). Written by App\Domain\Documents\Statements.
 *
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property Carbon $from_on
 * @property Carbon $to_on
 * @property Carbon $cutoff_at
 * @property int $requested_by_user_id
 * @property Carbon $requested_at
 * @property-read User|null $requestedBy
 */
class AccountStatement extends Model
{
    use HasPublicId;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'from_on' => 'date',
            'to_on' => 'date',
            'cutoff_at' => 'datetime',
            'requested_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
