<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Doc 02 §22.1 — marketing consent, append-only (05.12 §8.1): never
 * updated or deleted outside retention; the latest row per (user,
 * category, channel) is current. Unused while the marketing catalogue is
 * empty, by design.
 *
 * @property int $id
 * @property int $user_id
 * @property string $category
 * @property string $channel
 * @property bool $opted_in
 */
class NotificationPreference extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'category',
        'channel',
        'opted_in',
        'source',
        'consent_text_version',
        'ip',
        'user_agent',
        'actor_user_id',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'opted_in' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
