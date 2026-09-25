<?php

namespace App\Domain\Billing\Exceptions;

use RuntimeException;

/**
 * `seller.vat_number` is not configured (02 §21.3). A VAT invoice without
 * the supplier's registration number is not a valid VAT invoice, so none
 * is issued — and no invoice number is consumed.
 */
final class SellerVatNumberMissingException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct("Cannot issue a VAT invoice: system configuration 'seller.vat_number' is not set (02 §21.3).");
    }
}
