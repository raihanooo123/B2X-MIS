<?php

namespace App\Domain\Accounts;

/** 02 §25.1 `terms_versions.kind` (`terms_versions_kind_chk`). */
enum TermsKind: string
{
    case Trade = 'trade';
    case Sale = 'sale';

    public function label(): string
    {
        return match ($this) {
            self::Trade => 'Terms of trade',
            self::Sale => 'Terms of sale',
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
