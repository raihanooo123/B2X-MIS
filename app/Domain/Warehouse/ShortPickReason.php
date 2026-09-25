<?php

namespace App\Domain\Warehouse;

/**
 * 05.5 §5.4's closed list. Written as the `reason_code` of the short
 * pick's `adjustment` movement (stock_movements_reason_chk requires one).
 */
enum ShortPickReason: string
{
    case NotFound = 'not_found';
    case Damaged = 'damaged';
    case WrongLocation = 'wrong_location';
    case QuantityShort = 'quantity_short';

    public function label(): string
    {
        return match ($this) {
            self::NotFound => 'Not found',
            self::Damaged => 'Damaged',
            self::WrongLocation => 'Wrong location',
            self::QuantityShort => 'Quantity short',
        };
    }
}
