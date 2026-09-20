<?php

namespace App\Domain\Pricing;

/**
 * Mirrors `order_lines_price_source_chk` (Doc 02 §8.3) and the
 * `price_source` column doc 03 §4.2 assigns per resolution rank —
 * single source for a value that is otherwise just a bare string
 * repeated at every call site, per CLAUDE.md's enum convention.
 */
enum PriceSource: string
{
    case Contract = 'contract';
    case Customer = 'customer';
    case Promotion = 'promotion';
    case Tier = 'tier';
    case Base = 'base';
    case Manual = 'manual';
}
