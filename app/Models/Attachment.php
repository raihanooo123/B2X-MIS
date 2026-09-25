<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Doc 02 §14.2 — attachments. Generic polymorphic file attachment
 * (attachable_type/attachable_id). `path` is a storage-driver key, never a
 * public URL (07-nfr.md §6.3) — serve via a signed URL minted per request,
 * never expose this column directly in a response.
 *
 * @property int $id
 * @property string $public_id
 * @property string $attachable_type
 * @property int $attachable_id
 * @property string $disk
 * @property string $path
 * @property string|null $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property bool $is_customer_visible
 */
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory, HasPublicId, SoftDeletes;

    public $timestamps = false;

    protected $fillable = [
        'public_id',
        'attachable_type',
        'attachable_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'is_customer_visible',
        'uploaded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'is_customer_visible' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
