<?php

namespace App\Domain\Catalogue;

/**
 * Doc 02 §5.3 — builds `categories.path` (a native `ltree`) from a
 * category's own id and its parent's existing path.
 *
 * Segments are zero-padded to a fixed width rather than the bare id
 * string. `ltree` comparison (used by `ORDER BY path` for tree-order
 * display, and by `~`/`@>` queries elsewhere) is lexicographic per
 * label, not numeric — unpadded ids sort "11" before "9", which is
 * wrong and would only start showing up once the tenth-plus category is
 * ever created. Zero-padding to a fixed width up front makes
 * lexicographic and numeric order coincide for every id this system
 * will realistically ever reach, at no cost to correctness today.
 */
final class CategoryPath
{
    private const SEGMENT_WIDTH = 10;

    public static function build(?string $parentPath, int $categoryId): string
    {
        $segment = str_pad((string) $categoryId, self::SEGMENT_WIDTH, '0', STR_PAD_LEFT);

        return $parentPath === null || $parentPath === '' ? $segment : "{$parentPath}.{$segment}";
    }
}
