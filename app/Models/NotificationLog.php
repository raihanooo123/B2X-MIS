<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Doc 02 §22.2 — one row per message per recipient (05.12 §8.2). Written
 * only by App\Domain\Notifications; `status` is the latest outcome.
 *
 * @property int $id
 * @property string $notification_key
 * @property string $category
 * @property string $channel
 * @property string $template_version
 * @property int|null $user_id
 * @property int|null $company_id
 * @property string $recipient
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property int|null $attachment_id
 * @property string $dedup_key
 * @property string $status
 * @property int $attempts
 * @property string|null $provider_message_id
 * @property string|null $last_error
 * @property Carbon $queued_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 */
class NotificationLog extends Model
{
    protected $table = 'notification_log';

    public const CREATED_AT = null;

    protected $fillable = [
        'notification_key',
        'category',
        'channel',
        'template_version',
        'user_id',
        'company_id',
        'recipient',
        'subject_type',
        'subject_id',
        'attachment_id',
        'dedup_key',
        'status',
        'attempts',
        'provider_message_id',
        'last_error',
        'queued_at',
        'sent_at',
        'delivered_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Attachment, $this>
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }
}
