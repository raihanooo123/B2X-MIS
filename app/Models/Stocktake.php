<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\StocktakeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Doc 02 §14.7 — stocktakes. A counting session per location (04 §7.4,
 * 05.5 §8). Nothing reaches the ledger until posting; trading continues
 * during the count.
 *
 * @property int $id
 * @property string $public_id
 * @property int $location_id
 * @property string $status
 * @property bool $is_blind
 * @property int|null $started_by_user_id
 * @property int|null $posted_by_user_id
 * @property Carbon $started_at
 * @property Carbon|null $posted_at
 */
class Stocktake extends Model
{
    /** @use HasFactory<StocktakeFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'location_id',
        'status',
        'is_blind',
        'started_by_user_id',
        'posted_by_user_id',
        'started_at',
        'posted_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'is_blind' => 'boolean',
            'started_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    /**
     * @return HasMany<StocktakeLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StocktakeLine::class);
    }
}
