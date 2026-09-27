<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 02 §25.4 — vat_number_checks. One row per attempt, never updated: the
 * latest row per application is the current evidence.
 *
 * @property int $id
 * @property int $b2b_application_id
 * @property string $vat_number
 * @property string $authority
 * @property string $outcome
 * @property string|null $registered_name
 * @property array<string, mixed>|null $registered_address
 * @property string|null $consultation_number
 * @property Carbon|null $processed_at
 * @property string|null $failure_reason
 * @property int|null $requested_by_user_id
 * @property Carbon $checked_at
 */
class VatNumberCheck extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'b2b_application_id', 'vat_number', 'authority', 'outcome', 'registered_name', 'registered_address',
        'consultation_number', 'processed_at', 'failure_reason', 'requested_by_user_id', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'registered_address' => 'array',
            'processed_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }
}
