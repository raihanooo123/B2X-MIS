<?php

namespace App\Domain\Ordering;

/**
 * How a buyer pays at checkout (06 §9.3 `payment_method`; 05.2 §8.1).
 *
 *   - `card`       — paid by card before dispatch.
 *   - `bacs`       — paid by bank transfer before dispatch.
 *   - `on_account` — trade only, on the company's credit terms; takes a
 *                    credit hold (05.2 §8).
 *
 * The checkout strategies predate this enum and name the pay-before-
 * dispatch terms `prepay` (05.2 §8.1: "prepay → card or BACS required").
 * `strategyValue()` is the one place the two vocabularies meet.
 *
 * Neither card capture (Stripe, 07 §6.4) nor BACS reconciliation is built,
 * and `orders` has no column recording the method: a card or BACS order
 * is placed `unpaid` and paid before dispatch.
 */
enum PaymentMethod: string
{
    case Card = 'card';
    case Bacs = 'bacs';
    case OnAccount = 'on_account';

    public function strategyValue(): string
    {
        return match ($this) {
            self::Card => 'card',
            self::Bacs => 'prepay',
            self::OnAccount => 'on_account',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Card => 'Card',
            self::Bacs => 'Bank transfer (BACS)',
            self::OnAccount => 'On account',
        };
    }
}
