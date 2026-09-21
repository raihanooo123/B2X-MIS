<?php

namespace App\Domain\Catalogue;

use App\Models\Category;
use App\Models\CategoryClosure;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §5.3 — the write side of category hierarchy maintenance,
 * called after a category is created or its `parent_id` changes (an
 * "ActivateSku-style action, a Filament resource's save hook" —
 * CategoryClosureMaintainer's own docblock's words for exactly this).
 *
 * Two things need fixing on a reparent, not one: CategoryClosureMaintainer
 * already rebuilds `category_closure` correctly for the whole subtree
 * regardless of its size (verified live against a 3-level hierarchy).
 * What it deliberately does NOT touch is the `path` ltree column, since
 * that is presentation/query concern, not this table's own maintenance
 * — but `path` embeds the FULL ancestor chain, not just the direct
 * parent, so every existing descendant's `path` is now stale too, not
 * only the reparented category's own. This class is the one place that
 * fixes both.
 */
final class CategoryReparenter
{
    public function __construct(
        private readonly CategoryClosureMaintainer $closureMaintainer = new CategoryClosureMaintainer,
    ) {}

    public function apply(Category $category): void
    {
        DB::transaction(function () use ($category) {
            $parent = $category->parent_id === null
                ? null
                : Category::query()->find($category->parent_id);

            $category->forceFill([
                'path' => CategoryPath::build($parent?->path, $category->id),
                'depth' => $parent === null ? 0 : $parent->depth + 1,
            ])->save();

            $this->closureMaintainer->recompute($category);

            $this->cascadePathToDescendants($category);
        });
    }

    /**
     * Depth-ascending order guarantees each descendant's own direct
     * parent — either $category itself or an earlier-processed
     * descendant in this same loop — already has its final `path` by
     * the time it's read.
     */
    private function cascadePathToDescendants(Category $category): void
    {
        $descendantIds = CategoryClosure::query()
            ->where('ancestor_id', $category->id)
            ->where('descendant_id', '!=', $category->id)
            ->orderBy('depth')
            ->pluck('descendant_id');

        foreach ($descendantIds as $descendantId) {
            $descendant = Category::query()->find((int) $descendantId);

            if ($descendant === null || $descendant->parent_id === null) {
                continue;
            }

            $parent = Category::query()->find($descendant->parent_id);

            if ($parent === null) {
                continue;
            }

            $descendant->forceFill([
                'path' => CategoryPath::build($parent->path, $descendant->id),
                'depth' => $parent->depth + 1,
            ])->save();
        }
    }
}
