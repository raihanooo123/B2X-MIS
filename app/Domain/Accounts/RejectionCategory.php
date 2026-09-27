<?php

namespace App\Domain\Accounts;

/**
 * 02 §25.2 `b2b_applications.rejection_category`
 * (`b2b_applications_rejection_category_chk`), decided 2026-09-28.
 */
enum RejectionCategory: string
{
    case NotATradeBusiness = 'not_a_trade_business';
    case BusinessNotVerified = 'business_not_verified';
    case IdentityNotVerified = 'identity_not_verified';
    case DuplicateAccount = 'duplicate_account';
    case NoResponseToRequest = 'no_response_to_request';
    case OutsideTradingArea = 'outside_trading_area';
    case CreditOrRiskConcern = 'credit_or_risk_concern';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NotATradeBusiness => 'Not a trade business',
            self::BusinessNotVerified => 'Business could not be verified',
            self::IdentityNotVerified => 'Identity could not be verified',
            self::DuplicateAccount => 'Duplicate of an existing account',
            self::NoResponseToRequest => 'No response to an information request',
            self::OutsideTradingArea => 'Outside our trading area',
            self::CreditOrRiskConcern => 'Credit or risk concern',
            self::Other => 'Other',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
