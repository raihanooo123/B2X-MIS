<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 02 §25.1 — terms_acceptances. Append-only: the trigger rejects UPDATE,
 * and a row is deleted only with its application (ON DELETE CASCADE).
 *
 * @property int $id
 * @property int $terms_version_id
 * @property int $user_id
 * @property int|null $b2b_application_id
 * @property int|null $order_id
 * @property string $source
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon $accepted_at
 */
class TermsAcceptance extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'terms_version_id',
        'user_id',
        'b2b_application_id',
        'order_id',
        'source',
        'ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TermsVersion, $this> */
    public function termsVersion(): BelongsTo
    {
        return $this->belongsTo(TermsVersion::class);
    }
}
