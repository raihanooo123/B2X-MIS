<?php

namespace App\Models;

use App\Domain\Cms\PageKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 05.11 §3 — a legal or help page: its key and the one editable draft.
 * What the public sees is a published CmsPageVersion (CurrentPages);
 * saving the draft changes nothing public. Rows are created by the
 * migration, one per PageKey, and never added or deleted.
 *
 * @property int $id
 * @property string $page_key
 * @property string|null $draft_title
 * @property string|null $draft_meta_description
 * @property string|null $draft_body_markdown
 * @property Carbon|null $draft_updated_at
 * @property int|null $draft_updated_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CmsPage extends Model
{
    protected $fillable = [
        'draft_title',
        'draft_meta_description',
        'draft_body_markdown',
        'draft_updated_at',
        'draft_updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'draft_updated_at' => 'datetime',
        ];
    }

    public function key(): PageKey
    {
        return PageKey::from($this->page_key);
    }

    /** @return HasMany<CmsPageVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(CmsPageVersion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function draftUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'draft_updated_by_user_id');
    }
}
