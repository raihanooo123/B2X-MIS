<?php
namespace App\Models;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
/**
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
 */
class AccountCreditPayout extends Model
{
    use HasPublicId;
    public $timestamps = false;
    protected $guarded = ['id'];
    protected function casts(): array { return ['amount_minor' => 'integer', 'requested_at' => 'datetime', 'completed_at' => 'datetime']; }
}
