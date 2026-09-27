<?php

namespace App\Domain\Accounts;

/**
 * 02 §25.9: a shortfall in the verification evidence that approval may
 * proceed past only with a reviewer's acknowledgement. Computed at
 * approval, never stored on the application; the codes are recorded in
 * the `application.approved` audit row.
 */
enum VerificationWarning: string
{
    case VatNotChecked = 'vat_not_checked';
    case VatNotFound = 'vat_not_found';
    case VatStale = 'vat_stale';
    case CompaniesHouseNotChecked = 'companies_house_not_checked';
    case CompaniesHouseNotFound = 'companies_house_not_found';
    case CompaniesHouseNotActive = 'companies_house_not_active';
    case CompaniesHouseTypeMismatch = 'companies_house_type_mismatch';
    case CompaniesHouseStale = 'companies_house_stale';

    public function label(): string
    {
        return match ($this) {
            self::VatNotChecked => 'The VAT number has not been checked, or the check could not run.',
            self::VatNotFound => 'The VAT number is not registered.',
            self::VatStale => 'The VAT check is older than the evidence limit.',
            self::CompaniesHouseNotChecked => 'Companies House has not been checked, or the check could not run.',
            self::CompaniesHouseNotFound => 'Companies House has no company with this number.',
            self::CompaniesHouseNotActive => 'Companies House does not show the company as active.',
            self::CompaniesHouseTypeMismatch => 'The Companies House company type does not match the declared legal form.',
            self::CompaniesHouseStale => 'The Companies House check is older than the evidence limit.',
        };
    }
}
