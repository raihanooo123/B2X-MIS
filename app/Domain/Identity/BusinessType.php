<?php

namespace App\Domain\Identity;

/**
 * 05.2 §5.1's closed list for `b2b_applications.business_type`. 02 §4.6
 * gives the column no CHECK constraint yet; this enum is the single
 * source until one is added (CLAUDE.md enum convention).
 */
enum BusinessType: string
{
    case IndependentRetailer = 'independent_retailer';
    case Convenience = 'convenience';
    case MarketTrader = 'market_trader';
    case Catering = 'catering';
    case Online = 'online';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::IndependentRetailer => 'Independent retailer',
            self::Convenience => 'Convenience store',
            self::MarketTrader => 'Market trader',
            self::Catering => 'Catering',
            self::Online => 'Online seller',
            self::Other => 'Other',
        };
    }
}
