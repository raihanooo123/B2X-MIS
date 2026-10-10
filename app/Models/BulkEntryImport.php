<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 02 §31.3 — a staged bulk entry (paste, CSV, saved list or reorder) and
 * its reconciliation preview: temporary, never a shadow cart or order.
 * `rows` holds the 05.1 §14.1 row shape; internal ids stay server-side.
 * Expires after 24 hours; confirms at most once. Written by
 * App\Domain\Ordering\BulkEntry\BulkEntryImports only.
 *
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property int $user_id
 * @property string $tool
 * @property string $source
 * @property string $status
 * @property int $version
 * @property string $input_sha256
 * @property string|null $private_storage_path
 * @property list<array<string, mixed>> $rows
 * @property array<string, mixed>|null $confirmed_result
 * @property Carbon $created_at
 * @property Carbon $expires_at
 * @property Carbon|null $confirmed_at
 */
class BulkEntryImport extends Model
{
    use HasPublicId;

    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const CONFIRMED = 'confirmed';

    public const EXPIRED = 'expired';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'rows' => 'array',
            'confirmed_result' => 'array',
            'created_at' => 'datetime',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->status === self::EXPIRED || $this->expires_at->isPast();
    }
}
