<?php

namespace App\Models;

use Database\Factories\TaxClassFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Doc 02 §6.5 — tax_classes.
 */
class TaxClass extends Model
{
    /** @use HasFactory<TaxClassFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'xero_tax_type',
    ];

    /**
     * @return HasMany<TaxRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(TaxRate::class);
    }
}
