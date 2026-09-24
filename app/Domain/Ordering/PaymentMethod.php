<?php

namespace App\Domain\Ordering;

/**
 * How an order is paid — `orders.payment_method` (02 §18), mirrored here
 * as the single source for its CHECK list (CLAUDE.md enum convention).
 *
 *   - `card`       — paid by card before dispatch.
 *   - `bacs`       — paid by bank transfer before dispatch.
 *   - `on_account` — trade only, on the company's credit terms; takes a
 *                    credit hold (05.2 §8).
 *   - `prepay`     — paid before dispatch, method not specified. Accepted
 *                    by the checkout domain for orders placed outside web
 *                    checkout (phone, rep); web checkout offers card or
 *                    BACS instead, so the buyer's actual choice is recorded.
 *
 * Card capture (Stripe, 07 §6.4) and BACS reconciliation are not built:
 * card, BACS and prepay orders are placed `unpaid` and paid before dispatch.
 */
enum PaymentMethod: string
{
    case Card = 'card';
    case Bacs = 'bacs';
    case OnAccount = 'on_account';
    case Prepay = 'prepay';

    /** Paid before dispatch — every method except on-account. */
    public function isPrepayment(): bool
    {
        return $this !== self::OnAccount;
    }

    public function label(): string
    {
        return match ($this) {
            self::Card => 'Card',
            self::Bacs => 'Bank transfer (BACS)',
            self::OnAccount => 'On account',
            self::Prepay => 'Payment before dispatch',
        };
    }
}
