<?php

namespace App\Domain\Accounts;

/**
 * 02 §25.3 `legal_form` on `b2b_applications` and `companies`. Separate
 * from BusinessType, which is the trade sector: the legal form decides
 * which evidence can exist.
 */
enum LegalForm: string
{
    case SoleTrader = 'sole_trader';
    case Partnership = 'partnership';
    case LimitedCompany = 'limited_company';
    case Llp = 'llp';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::SoleTrader => 'Sole trader',
            self::Partnership => 'Partnership',
            self::LimitedCompany => 'Limited company',
            self::Llp => 'Limited liability partnership (LLP)',
            self::Other => 'Other (charity, co-operative, public body…)',
        };
    }

    /** Companies House must know these forms (02 §25.3). */
    public function requiresCompaniesHouseNumber(): bool
    {
        return $this === self::LimitedCompany || $this === self::Llp;
    }

    /** @return list<array{value: string, label: string}> */
    public static function choices(): array
    {
        return array_map(fn (self $form) => ['value' => $form->value, 'label' => $form->label()], self::cases());
    }
}
