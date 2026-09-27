<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 02 §25.5 — companies_house_checks. One row per attempt, never updated.
 * `company_status` and `company_type` are Companies House's own values,
 * stored verbatim.
 *
 * @property int $id
 * @property int $b2b_application_id
 * @property string $company_number
 * @property string $outcome
 * @property string|null $company_status
 * @property string|null $company_type
 * @property string|null $registered_name
 * @property array<string, mixed>|null $registered_office
 * @property Carbon|null $incorporated_on
 * @property string|null $failure_reason
 * @property int|null $requested_by_user_id
 * @property Carbon $checked_at
 */
class CompaniesHouseCheck extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'b2b_application_id', 'company_number', 'outcome', 'company_status', 'company_type', 'registered_name',
        'registered_office', 'incorporated_on', 'failure_reason', 'requested_by_user_id', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'registered_office' => 'array',
            'incorporated_on' => 'date',
            'checked_at' => 'datetime',
        ];
    }
}
