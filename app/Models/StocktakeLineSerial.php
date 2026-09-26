<?php

namespace App\Models;

use Database\Factories\StocktakeLineSerialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Doc 02 §24.2 — stocktake_line_serials: one serial scanned into a
 * stocktake line. Deletable while the stocktake is open (a mis-scan);
 * the record of what was found once it is posted.
 *
 * @property int $id
 * @property int $stocktake_line_id
 * @property string $serial_number
 * @property int|null $serial_id
 * @property int|null $scanned_by_user_id
 * @property Carbon $scanned_at
 */
class StocktakeLineSerial extends Model
{
    /** @use HasFactory<StocktakeLineSerialFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'stocktake_line_id',
        'serial_number',
        'serial_id',
        'scanned_by_user_id',
        'scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StocktakeLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(StocktakeLine::class, 'stocktake_line_id');
    }

    /**
     * @return BelongsTo<StockSerial, $this>
     */
    public function serial(): BelongsTo
    {
        return $this->belongsTo(StockSerial::class, 'serial_id');
    }
}
