<?php

namespace App\Domain\Accounts;

/**
 * 02 §25.1 `terms_acceptances.source` (`terms_acceptances_source_chk`).
 * `checkout` is a public customer's terms of sale, written by
 * CheckoutService in the order transaction (05.15 §6.1 step 4).
 */
enum TermsAcceptanceSource: string
{
    case TradeApplication = 'trade_application';
    case Checkout = 'checkout';
}
