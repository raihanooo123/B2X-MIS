<?php

namespace App\Filament\Support;

/**
 * Labels and badge colours for the catalogue's two status sets, so lists,
 * forms, filters, tabs and detail pages say the same thing. The values are
 * the `CHECK`-constrained columns of 02 §5: products and skus share the
 * lifecycle set, categories and brands the visibility set.
 */
final class CatalogueStatus
{
    /** products.status, skus.status */
    public const LIFECYCLE = [
        'draft' => 'Draft',
        'active' => 'Active',
        'coming_soon' => 'Coming soon',
        'discontinued' => 'Discontinued',
        'archived' => 'Archived',
    ];

    /** categories.status, brands.status */
    public const VISIBILITY = [
        'active' => 'Active',
        'hidden' => 'Hidden',
        'archived' => 'Archived',
    ];

    public static function label(?string $status): string
    {
        return self::LIFECYCLE[$status] ?? self::VISIBILITY[$status] ?? (string) $status;
    }

    public static function color(?string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'coming_soon' => 'info',
            'discontinued' => 'warning',
            'archived' => 'danger',
            default => 'gray',
        };
    }

    public static function icon(?string $status): string
    {
        return match ($status) {
            'active' => 'heroicon-m-check-circle',
            'draft' => 'heroicon-m-pencil-square',
            'coming_soon' => 'heroicon-m-clock',
            'discontinued' => 'heroicon-m-pause-circle',
            'hidden' => 'heroicon-m-eye-slash',
            'archived' => 'heroicon-m-archive-box',
            default => 'heroicon-m-minus-circle',
        };
    }
}
