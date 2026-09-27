<?php

namespace App\Domain\Accounts\Verification;

use App\Domain\Accounts\VerificationWarning;
use App\Models\CompaniesHouseCheck;
use App\Models\VatNumberCheck;

/**
 * What the latest evidence says about one application (02 §25.9): a
 * refusal, if any, and the warnings approval must acknowledge. Built by
 * BusinessVerification::assess() for the approval rule, the review screen
 * and the queue badge alike, so all three always agree.
 */
final readonly class VerificationAssessment
{
    /**
     * @param  list<VerificationWarning>  $warnings
     */
    public function __construct(
        public bool $vatApplies,
        public bool $companiesHouseApplies,
        public ?VatNumberCheck $vat,
        public ?CompaniesHouseCheck $companiesHouse,
        public bool $vatStale,
        public bool $companiesHouseStale,
        public ?string $refusal,
        public array $warnings,
    ) {}

    /** The codes as the approval audit records them: sorted, comma-separated. */
    public function warningCodes(): string
    {
        $codes = array_map(fn (VerificationWarning $w) => $w->value, $this->warnings);
        sort($codes);

        return implode(',', $codes);
    }

    /** 05.2 §17.3's queue badge. */
    public function badge(): string
    {
        if (! $this->vatApplies && ! $this->companiesHouseApplies) {
            return 'Nothing to check';
        }
        if ($this->refusal !== null) {
            return 'Needs attention';
        }
        $notChecked = [VerificationWarning::VatNotChecked, VerificationWarning::CompaniesHouseNotChecked];
        foreach ($this->warnings as $warning) {
            if (! in_array($warning, $notChecked, true)) {
                return 'Needs attention';
            }
        }

        return $this->warnings === [] ? 'Verified' : 'Unchecked';
    }
}
