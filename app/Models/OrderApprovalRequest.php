<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 02 §31.1 — one auditable decision on a trade order: `buyer_limit` for a
 * company approver, `credit_exception` for accounts. One per order and
 * kind; written by App\Domain\Credit only.
 *
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property int $order_id
 * @property int $requested_by_user_id
 * @property string $approval_kind
 * @property string $status
 * @property int $order_gross_minor
 * @property Carbon $requested_at
 * @property Carbon $expires_at
 * @property Carbon|null $decided_at
 * @property int|null $decided_by_user_id
 * @property string|null $decision_reason
 * @property-read Order|null $order
 * @property-read User|null $buyer
 * @property-read User|null $decidedBy
 * @property-read Company|null $company
 */
class OrderApprovalRequest extends Model
{
    use HasPublicId;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'order_id', 'requested_by_user_id', 'approval_kind', 'status',
        'order_gross_minor', 'requested_at', 'expires_at', 'decided_at', 'decided_by_user_id', 'decision_reason',
    ];

    protected function casts(): array
    {
        return [
            'order_gross_minor' => 'integer',
            'requested_at' => 'datetime',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
