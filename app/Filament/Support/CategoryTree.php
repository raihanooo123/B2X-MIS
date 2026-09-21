<?php

namespace App\Filament\Support;

use App\Models\Category;
use App\Models\CategoryClosure;

/**
 * Indented, path-ordered category options for select fields — reused by
 * ProductResource's category picker and CategoryResource's own parent
 * picker.
 */
final class CategoryTree
{
    /**
     * @return array<int, string> category id => indented label, in tree order
     */
    public static function options(?int $excludingSubtreeRootId = null): array
    {
        $query = Category::query()->orderBy('path');

        if ($excludingSubtreeRootId !== null) {
            // A category can never become its own ancestor: excludes
            // itself and every descendant, found via the closure table
            // built for exactly this kind of query (02 §5.3).
            $excludedIds = CategoryClosure::query()
                ->where('ancestor_id', $excludingSubtreeRootId)
                ->pluck('descendant_id');

            $query->whereNotIn('id', $excludedIds);
        }

        return $query->get(['id', 'name', 'depth'])
            ->mapWithKeys(fn (Category $category) => [
                $category->id => str_repeat('— ', $category->depth).$category->name,
            ])
            ->all();
    }
}
