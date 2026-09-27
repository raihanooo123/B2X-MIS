<?php

namespace App\Domain\Accounts;

/** 02 §25.4 `vat_number_checks.outcome`. */
enum VatCheckOutcome: string
{
    case Valid = 'valid';
    case NotFound = 'not_found';
    case Unchecked = 'unchecked';
}
