<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 02 §31.1 — spendable account balance paid back out, reserved before the
 * external call. Written by App\Domain\Credit\CreditPayouts only.
 *
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property string $event_key
 * @property string $method
 * @property int $amount_minor
 * @property int|null $source_payment_id
 * @property int|null $completed_payment_id
 * @property string $destination_reference
 * @property string $status
 * @property int $requested_by_user_id
 * @property int|null $approved_by_user_id
 * @property Carbon $requested_at
 * @property Carbon|null $completed_at
 * @property-read User|null $requestedBy
 * @property-read User|null $approvedBy
 */
class AccountCreditPayout extends Model
{
    use HasPublicId;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
