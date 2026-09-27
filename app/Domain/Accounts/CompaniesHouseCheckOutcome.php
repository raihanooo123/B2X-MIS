<?php

namespace App\Domain\Accounts;

/** 02 §25.5 `companies_house_checks.outcome`. */
enum CompaniesHouseCheckOutcome: string
{
    case Found = 'found';
    case NotFound = 'not_found';
    case Unchecked = 'unchecked';
}
