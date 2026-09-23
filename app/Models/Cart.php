<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Doc 02 §14.3 — carts. Pre-order state; `company_id`/`user_id` are both
 * nullable (a guest has neither yet). `session_token` is the only identity
 * a fresh cart has, and is how a guest cart is merged onto a company/user
 * at login (05.13, pending).
 *
 * @property string $public_id
 * @property string $session_token
 * @property int|null $company_id
 * @property int|null $user_id
 */
class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'session_token',
        'company_id',
        'user_id',
    ];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CartLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CartLine::class);
    }
}
