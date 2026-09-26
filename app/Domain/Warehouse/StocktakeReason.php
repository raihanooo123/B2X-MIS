<?php

namespace App\Domain\Warehouse;

/**
 * 02 §24.3's closed list for a stocktake variance — the `reason_code` of
 * the `stocktake` movement (stock_movements_reason_chk requires one).
 */
enum StocktakeReason: string
{
    case MiscountCorrected = 'miscount_corrected';
    case Damaged = 'damaged';
    case LossOrTheft = 'loss_or_theft';
    case UnrecordedReceipt = 'unrecorded_receipt';
    case UnrecordedDispatch = 'unrecorded_dispatch';
    case FoundStock = 'found_stock';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::MiscountCorrected => 'Earlier miscount corrected',
            self::Damaged => 'Damaged',
            self::LossOrTheft => 'Loss or theft',
            self::UnrecordedReceipt => 'Unrecorded receipt',
            self::UnrecordedDispatch => 'Unrecorded dispatch',
            self::FoundStock => 'Found stock',
            self::Other => 'Other',
        };
    }
}
