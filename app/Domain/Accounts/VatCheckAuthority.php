<?php

namespace App\Domain\Accounts;

/** 02 §25.4 `vat_number_checks.authority`: HMRC for `GB`, VIES for `XI`. */
enum VatCheckAuthority: string
{
    case Hmrc = 'hmrc';
    case Vies = 'vies';

    public static function forNumber(string $vatNumber): self
    {
        return str_starts_with($vatNumber, 'XI') ? self::Vies : self::Hmrc;
    }

    public function label(): string
    {
        return match ($this) {
            self::Hmrc => 'HMRC',
            self::Vies => 'VIES',
        };
    }
}
