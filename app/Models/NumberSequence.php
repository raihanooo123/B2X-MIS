<?php

namespace App\Models;

use Database\Factories\NumberSequenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Doc 02 §11.3 — number_sequences. Gapless document numbering.
 *
 * Incremented with `SELECT ... FOR UPDATE` inside the same transaction
 * that creates the document, never via application-level read-then-write.
 * That locking is domain logic and belongs in the service issuing the
 * document, not on this model.
 *
 * @property string $key_name
 * @property string $prefix
 * @property int $next_value
 * @property int $padding
 */
class NumberSequence extends Model
{
    /** @use HasFactory<NumberSequenceFactory> */
    use HasFactory;

    protected $primaryKey = 'key_name';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'key_name',
        'prefix',
        'next_value',
        'padding',
    ];

    protected function casts(): array
    {
        return [
            'next_value' => 'integer',
            'padding' => 'integer',
        ];
    }
}
