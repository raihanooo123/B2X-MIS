<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Doc 02 §5.3 — categories.
 *
 * `path` is a native `ltree`; left uncast (raw label-path string) since
 * ancestor/descendant queries use its `@>`/`<@`/`~` operators directly in
 * SQL rather than through PHP. `category_closure` (see CategoryClosure)
 * is the hot-path equality join for "this category and every descendant"
 * and is maintained alongside `path`, not derived from it at read time.
 */
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'path',
        'depth',
        'position',
        'status',
        'icon_media_id',
        'meta_title',
        'meta_description',
    ];

    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Secondary/cross-listing product membership (§5.9) — the reverse
     * direction of Product::categories(), required alongside it per the
     * §5.7 rule: both directions of a many-to-many, always.
     *
     * @return BelongsToMany<Product, $this, ProductCategory, 'pivot'>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_categories')
            ->using(ProductCategory::class)
            ->withPivot(['position']);
    }
}
