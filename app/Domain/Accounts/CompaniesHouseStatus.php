<?php

namespace App\Domain\Accounts;

/**
 * 02 §25.5, §25.9: Companies House's `company_status` vocabulary is theirs
 * and is stored verbatim (no CHECK). These are the statuses that refuse
 * approval of a limited company or LLP: ended, or insolvent / under an
 * office-holder. `voluntary-arrangement`, and any status Companies House
 * adds later, need acknowledgement only.
 */
final class CompaniesHouseStatus
{
    public const ACTIVE = 'active';

    public const REFUSES_APPROVAL = [
        'dissolved',
        'removed',
        'closed',
        'converted-closed',
        'liquidation',
        'administration',
        'receivership',
        'insolvency-proceedings',
    ];

    public static function refusesApproval(string $status): bool
    {
        return in_array($status, self::REFUSES_APPROVAL, true);
    }
}
