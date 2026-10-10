<?php

namespace App\Filament\Support;

/**
 * Labels and badge colours for suppliers and purchase orders, so lists,
 * forms, tabs and detail pages say the same thing. The values are the
 * `CHECK`-constrained `status` columns of `suppliers` and `purchase_orders`.
 */
final class PurchasingStatus
{
    public const SUPPLIER = [
        'active' => 'Active',
        'on_hold' => 'On hold',
        'archived' => 'Archived',
    ];

    public const PURCHASE_ORDER = [
        'draft' => 'Draft',
        'sent' => 'Sent',
        'confirmed' => 'Confirmed',
        'in_production' => 'In production',
        'shipped' => 'Shipped',
        'part_received' => 'Part received',
        'received' => 'Received',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
    ];

    public static function label(?string $status): string
    {
        return self::PURCHASE_ORDER[$status] ?? self::SUPPLIER[$status] ?? (string) $status;
    }

    public static function color(?string $status): string
    {
        return match ($status) {
            'active', 'received', 'closed' => 'success',
            'sent', 'confirmed', 'in_production' => 'info',
            'shipped', 'part_received' => 'primary',
            'on_hold' => 'warning',
            'cancelled', 'archived' => 'danger',
            default => 'gray',
        };
    }

    public static function icon(?string $status): string
    {
        return match ($status) {
            'draft' => 'heroicon-m-pencil-square',
            'sent' => 'heroicon-m-paper-airplane',
            'confirmed', 'active' => 'heroicon-m-check-circle',
            'in_production' => 'heroicon-m-cog-6-tooth',
            'shipped' => 'heroicon-m-truck',
            'part_received' => 'heroicon-m-inbox-arrow-down',
            'received', 'closed' => 'heroicon-m-check-badge',
            'on_hold' => 'heroicon-m-pause-circle',
            'cancelled' => 'heroicon-m-x-circle',
            'archived' => 'heroicon-m-archive-box',
            default => 'heroicon-m-minus-circle',
        };
    }
}
