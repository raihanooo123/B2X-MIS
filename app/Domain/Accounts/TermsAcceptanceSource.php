<?php

namespace App\Domain\Accounts;

/**
 * 02 §25.1 `terms_acceptances.source` (`terms_acceptances_source_chk`).
 * `checkout` is for the later terms-of-sale slice; nothing writes it yet.
 */
enum TermsAcceptanceSource: string
{
    case TradeApplication = 'trade_application';
    case Checkout = 'checkout';
}
