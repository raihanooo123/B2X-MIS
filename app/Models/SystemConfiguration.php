<?php

namespace App\Models;

use Database\Factories\SystemConfigurationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Doc 02 §2.7 — system_configurations. Resolution is most-specific-wins:
 * company -> location -> global -> code default. Resolution and the
 * snapshot-onto-transaction rule are domain logic and live elsewhere;
 * this model is deliberately thin.
 *
 * @property string $config_key
 * @property string $scope
 * @property int|null $value_int
 */
class SystemConfiguration extends Model
{
    /** @use HasFactory<SystemConfigurationFactory> */
    use HasFactory;

    protected $fillable = [
        'config_key',
        'scope',
        'location_id',
        'company_id',
        'value_type',
        'value_int',
        'value_text',
        'value_json',
        'description',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'value_int' => 'integer',
            'value_json' => 'array',
        ];
    }

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
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
