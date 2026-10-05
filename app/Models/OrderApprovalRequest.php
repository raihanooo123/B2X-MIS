<?php
namespace App\Models;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
/**
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
 * @property int|null $decided_by_user_id
 * @property string|null $decision_reason
 */
class OrderApprovalRequest extends Model
{
    use HasPublicId;
    public $timestamps = false;
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return ['order_gross_minor' => 'integer', 'requested_at' => 'datetime', 'expires_at' => 'datetime', 'decided_at' => 'datetime'];
    }
    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    /** @return BelongsTo<User, $this> */
    public function buyer(): BelongsTo { return $this->belongsTo(User::class, 'requested_by_user_id'); }
    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
}
