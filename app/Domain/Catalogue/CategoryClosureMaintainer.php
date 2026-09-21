<?php

namespace App\Domain\Catalogue;

use App\Models\Category;
use App\Models\CategoryClosure;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §5.3 — maintains `category_closure` whenever a category is created
 * or its `parent_id` changes. Deliberately not a model observer: the
 * doc's own note on `CategoryClosure` says this maintenance "is domain
 * logic and lives with the catalogue service, not on this model" — callers
 * (an `ActivateSku`-style action, a Filament resource's save hook, etc.)
 * call this explicitly after the `Category` row itself is saved.
 *
 * Standard closure-table re-parenting algorithm, correct for both a
 * brand-new category (no existing closure rows at all) and moving an
 * existing subtree with descendants — both degenerate correctly from the
 * same three steps: find the subtree, drop its stale external ancestor
 * links, re-link the whole subtree under the new parent's ancestor chain.
 */
final class CategoryClosureMaintainer
{
    public function recompute(Category $category): void
    {
        DB::transaction(function () use ($category) {
            $categoryId = $category->id;

            // Every node this category is currently an ancestor of,
            // keyed by descendant id => depth from $category itself.
            // Always includes itself at depth 0, even for a brand-new
            // category with no closure rows yet.
            $subtree = CategoryClosure::query()
                ->where('ancestor_id', $categoryId)
                ->pluck('depth', 'descendant_id')
                ->all();
            $subtree[$categoryId] = 0;
            $subtreeIds = array_keys($subtree);

            // Drop every closure row linking a subtree member to an
            // ancestor OUTSIDE the subtree — the stale links from
            // wherever this category used to sit in the tree. Links
            // entirely within the subtree (including every member's own
            // self-reference, and links between two subtree descendants)
            // are untouched, since their ancestor is also in $subtreeIds.
            CategoryClosure::query()
                ->whereIn('descendant_id', $subtreeIds)
                ->whereNotIn('ancestor_id', $subtreeIds)
                ->delete();

            // The new ancestor chain: every ancestor of the new parent
            // (including the parent itself, at depth 0 from itself). A
            // root category (parent_id NULL) has none.
            $newAncestors = $category->parent_id === null
                ? []
                : CategoryClosure::query()
                    ->where('descendant_id', $category->parent_id)
                    ->pluck('depth', 'ancestor_id')
                    ->all();

            $rows = [];
            foreach ($subtreeIds as $descendantId) {
                // $category's own link to each subtree member (including
                // its self-reference) — re-inserted idempotently even
                // though most of these survived the delete above.
                $rows[] = [
                    'ancestor_id' => $categoryId,
                    'descendant_id' => $descendantId,
                    'depth' => $subtree[$descendantId],
                ];

                foreach ($newAncestors as $ancestorId => $depthToParent) {
                    $rows[] = [
                        'ancestor_id' => $ancestorId,
                        'descendant_id' => $descendantId,
                        'depth' => $depthToParent + 1 + $subtree[$descendantId],
                    ];
                }
            }

            CategoryClosure::query()->upsert($rows, ['ancestor_id', 'descendant_id'], ['depth']);
        });
    }
}
