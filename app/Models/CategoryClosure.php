<?php

namespace App\Models;

use Database\Factories\CategoryClosureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Doc 02 §5.3 — category_closure. The equality-joinable ancestor/descendant
 * set that powers "all active products in this category and every
 * descendant" in one join. Maintained transactionally whenever a
 * category's parent changes — that maintenance is domain logic and lives
 * with the catalogue service, not on this model.
 */
class CategoryClosure extends Model
{
    /** @use HasFactory<CategoryClosureFactory> */
    use HasFactory;

    protected $table = 'category_closure';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = [
        'ancestor_id',
        'descendant_id',
        'depth',
    ];

    protected function casts(): array
    {
        return [
            'depth' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function ancestor(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'ancestor_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function descendant(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'descendant_id');
    }
}
