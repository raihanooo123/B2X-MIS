<?php

namespace App\Domain\Cms;

/**
 * 05.11 §2.1 — the closed list of legal and help pages, mirroring
 * `cms_pages_key_chk`. Each has a fixed public path, never a catch-all,
 * so a page can never shadow another route.
 */
enum PageKey: string
{
    case Privacy = 'privacy';
    case Cookies = 'cookies';
    case Delivery = 'delivery';
    case Returns = 'returns';
    case Contact = 'contact';

    public function label(): string
    {
        return match ($this) {
            self::Privacy => 'Privacy notice',
            self::Cookies => 'Cookies',
            self::Delivery => 'Delivery',
            self::Returns => 'Returns & cancellations',
            self::Contact => 'Contact',
        };
    }

    public function path(): string
    {
        return '/'.$this->value;
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
