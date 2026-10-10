<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 02 §31.2 — one archived document version: the payload fixed when the
 * source was issued (seller, customer, lines, totals, template version),
 * and its PDF once rendered. Payload and a ready archive are immutable
 * (database guard); status moves pending → rendering → ready | failed.
 * Written by App\Domain\Documents\DocumentRenders only.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $company_id
 * @property string $document_type
 * @property int $source_id
 * @property int $version
 * @property string $template_version
 * @property array<string, mixed> $payload
 * @property string $payload_sha256
 * @property string $status
 * @property int|null $attachment_id
 * @property int|null $requested_by_user_id
 * @property Carbon $created_at
 * @property Carbon|null $rendered_at
 * @property string|null $error_code
 * @property-read Attachment|null $attachment
 */
class DocumentRender extends Model
{
    use HasPublicId;

    public const PENDING = 'pending';

    public const RENDERING = 'rendering';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'version' => 'integer',
            'created_at' => 'datetime',
            'rendered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Attachment, $this> */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }
}
