<?php

namespace App\Domain\Ordering\BulkEntry;

/** Plain words for each reconciliation outcome (05.1 §7.1), shown on screen and in the rejection CSV. */
final class ImportMessages
{
    /** @param array<string, mixed> $row */
    public static function problem(array $row): string
    {
        $suggested = $row['suggested_pack_qty'] ?? null;

        return match ($row['error_code'] ?? null) {
            null => '',
            'malformed' => 'Could not read this line. Use a code, then a quantity.',
            'missing_code' => 'No product code.',
            'invalid_quantity' => 'The quantity is not a number.',
            'fractional_quantity' => 'Quantities are whole packs, not fractions.',
            'non_positive_quantity' => 'The quantity must be 1 or more.',
            'quantity_too_large' => 'The quantity is too large.',
            'sku_not_found' => 'No product with this code.',
            'ambiguous_sku' => 'More than one product matches this code. Check its capitals.',
            'not_purchasable' => 'This product cannot be ordered at the moment.',
            'pack_not_available' => 'This pack size is not sold for this product.',
            'no_default_pack' => 'Choose a pack size for this product.',
            'insufficient_stock' => 'Not enough in stock for the whole quantity. Checkout will confirm what can be sent.',
            'below_minimum' => $suggested === null ? 'Below the minimum order quantity.' : "Below the minimum order. Suggested: {$suggested} packs.",
            'not_a_case_multiple' => $suggested === null ? 'Must be ordered in whole cases.' : "Must be ordered in whole cases. Suggested: {$suggested} packs.",
            'above_maximum' => $suggested === null ? 'Above the most that can be ordered at once.' : "Above the maximum per order. Suggested: {$suggested} packs.",
            default => str_starts_with((string) $row['error_code'], 'sku_')
                ? 'This product is '.str_replace('_', ' ', substr((string) $row['error_code'], 4)).'.'
                : 'This row cannot be added.',
        };
    }
}
