<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 05.11 §2.2 — a published version of a legal or help page. Immutable
 * once inserted: the `cms_page_versions_immutable` trigger rejects UPDATE
 * and DELETE, so a correction is always a new version, published through
 * CmsPublisher. Evidence of what a customer was told on a given date.
 *
 * @property int $id
 * @property int $cms_page_id
 * @property int $version_no
 * @property string $title
 * @property string|null $meta_description
 * @property string $body_markdown
 * @property string $body_sha256
 * @property Carbon $effective_from
 * @property string|null $change_note
 * @property int $published_by_user_id
 * @property Carbon $created_at
 */
class CmsPageVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'cms_page_id',
        'version_no',
        'title',
        'meta_description',
        'body_markdown',
        'body_sha256',
        'effective_from',
        'change_note',
        'published_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CmsPage, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(CmsPage::class, 'cms_page_id');
    }

    /** @return BelongsTo<User, $this> */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }
}
