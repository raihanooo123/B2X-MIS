<?php

namespace App\Domain\Credit;

enum CreditMovementType: string
{
    case CreditNote = 'credit_note';
    case AppliedToOrder = 'applied_to_order';
    case AppliedToInvoice = 'applied_to_invoice';
    case PayoutReserved = 'payout_reserved';
    case RefundedToBank = 'refunded_to_bank';
    case RefundedToCard = 'refunded_to_card';
    case Adjustment = 'adjustment';
    case Expiry = 'expiry';
    case Reversal = 'reversal';
}
