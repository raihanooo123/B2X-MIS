<?php

namespace App\Models;

use App\Domain\Accounts\TermsKind;
use Database\Factories\TermsVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 02 §25.1 — terms_versions. Immutable once inserted: the
 * `terms_versions_immutable` trigger rejects UPDATE and DELETE, so a
 * change is always a new version, published through TermsPublisher.
 *
 * @property int $id
 * @property string $kind
 * @property string $version
 * @property string $body_markdown
 * @property string $body_sha256
 * @property Carbon $effective_from
 * @property int $published_by_user_id
 * @property Carbon $created_at
 */
class TermsVersion extends Model
{
    /** @use HasFactory<TermsVersionFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'kind',
        'version',
        'body_markdown',
        'body_sha256',
        'effective_from',
        'published_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The version in force: the latest `effective_from <= now()` for the
     * kind, served by `terms_versions_kind_effective_uq` (02 §25.1).
     */
    public static function current(TermsKind $kind): ?self
    {
        return self::query()
            ->where('kind', $kind->value)
            ->where('effective_from', '<=', now())
            ->orderByDesc('effective_from')
            ->first();
    }

    /** @return BelongsTo<User, $this> */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }
}
