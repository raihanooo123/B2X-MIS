<?php

namespace App\Domain\Billing;

/**
 * `companies.payment_terms` (02 §4.3 `companies_terms_chk`, 05.2 §9).
 * InvoiceService and the InvoiceIssued notice still carry their own
 * copies of these codes; they should move onto this enum.
 */
enum PaymentTerms: string
{
    case Prepay = 'prepay';
    case Net7 = 'net7';
    case Net14 = 'net14';
    case Net30 = 'net30';
    case Net60 = 'net60';

    public function label(): string
    {
        return match ($this) {
            self::Prepay => 'Payment in advance',
            self::Net7 => '7 days net',
            self::Net14 => '14 days net',
            self::Net30 => '30 days net',
            self::Net60 => '60 days net',
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
