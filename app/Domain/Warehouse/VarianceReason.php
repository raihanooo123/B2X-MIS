<?php

namespace App\Domain\Warehouse;

/**
 * `purchase_order_lines.variance_reason` (05.7 §5) — 05.5 §4.4's closed
 * list, mirrored here as the single source for the CHECK.
 */
enum VarianceReason: string
{
    case ShortShipped = 'short_shipped';
    case OverShipped = 'over_shipped';
    case DamagedInTransit = 'damaged_in_transit';
    case SupplierSubstitution = 'supplier_substitution';
    case MiscountCorrected = 'miscount_corrected';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ShortShipped => 'Short shipped',
            self::OverShipped => 'Over shipped',
            self::DamagedInTransit => 'Damaged in transit',
            self::SupplierSubstitution => 'Supplier substitution',
            self::MiscountCorrected => 'Miscount corrected',
            self::Other => 'Other',
        };
    }
}
