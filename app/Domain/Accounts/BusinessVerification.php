<?php

namespace App\Domain\Accounts;

use App\Domain\Accounts\Verification\CompaniesHouseClient;
use App\Domain\Accounts\Verification\CompanyLookup;
use App\Domain\Accounts\Verification\HmrcVatClient;
use App\Domain\Accounts\Verification\VatLookup;
use App\Domain\Accounts\Verification\VerificationAssessment;
use App\Domain\Accounts\Verification\ViesVatClient;
use App\Domain\Billing\SellerDetails;
use App\Models\B2bApplication;
use App\Models\CompaniesHouseCheck;
use App\Models\SystemConfiguration;
use App\Models\VatNumberCheck;
use Carbon\CarbonInterface;

/**
 * 02 §25.4–25.5, §25.9: the VAT number (HMRC for `GB`, VIES for `XI`) and
 * Companies House checks of a trade application, and what their latest
 * results mean for approval.
 *
 * Checks run in VerifyApplicationBusiness, after the application commits
 * — never in a transaction, never on the applicant's request. An outage
 * is recorded as `unchecked` with its reason; it never blocks or delays an
 * application. Each attempt is a new row; nothing is updated.
 */
final class BusinessVerification
{
    public const XI_VAT_NUMBER_KEY = 'seller.xi_vat_number';

    public function __construct(
        private readonly HmrcVatClient $hmrc = new HmrcVatClient,
        private readonly ViesVatClient $vies = new ViesVatClient,
        private readonly CompaniesHouseClient $companiesHouse = new CompaniesHouseClient,
        private readonly ApplicationSettings $settings = new ApplicationSettings,
    ) {}

    /**
     * Runs every check the application calls for that this job has not
     * already written. A transient failure (timeout, unavailable,
     * rate-limited) is held back for a retry unless this is the final
     * attempt, so a blip leaves one clean row rather than a failure.
     *
     * @param  CarbonInterface  $since  when the job was dispatched: checks written since are this job's
     * @return bool whether a check is waiting for a retry
     */
    public function run(B2bApplication $application, ?int $requestedByUserId, bool $finalAttempt, CarbonInterface $since): bool
    {
        $retry = false;

        $vatNumber = $application->vat_number;
        if ($vatNumber !== null && ! VatNumberCheck::query()->where('b2b_application_id', $application->id)->where('checked_at', '>=', $since)->exists()) {
            $authority = VatCheckAuthority::forNumber($vatNumber);
            $lookup = $authority === VatCheckAuthority::Vies
                ? $this->vies->lookup($vatNumber, $this->sellerNumber(self::XI_VAT_NUMBER_KEY, 'XI'))
                : $this->hmrc->lookup($vatNumber, $this->sellerNumber(SellerDetails::VAT_NUMBER_KEY, 'GB'));

            if (! $finalAttempt && $lookup->failure?->isTransient() === true) {
                $retry = true;
            } else {
                $this->recordVat($application, $vatNumber, $authority, $lookup, $requestedByUserId);
            }
        }

        $companyNumber = $application->registration_number;
        if ($companyNumber !== null && ! CompaniesHouseCheck::query()->where('b2b_application_id', $application->id)->where('checked_at', '>=', $since)->exists()) {
            $lookup = $this->companiesHouse->lookup($companyNumber);

            if (! $finalAttempt && $lookup->failure?->isTransient() === true) {
                $retry = true;
            } else {
                $this->recordCompany($application, $companyNumber, $lookup, $requestedByUserId);
            }
        }

        return $retry;
    }

    /**
     * The checks this application calls for, e.g. ['companies_house', 'vat'].
     *
     * @return list<string>
     */
    public static function checksFor(B2bApplication $application): array
    {
        $checks = [];
        if ($application->registration_number !== null) {
            $checks[] = 'companies_house';
        }
        if ($application->vat_number !== null) {
            $checks[] = 'vat';
        }

        return $checks;
    }

    /** 02 §25.9, read from the latest check of each type. */
    public function assess(B2bApplication $application): VerificationAssessment
    {
        $maxAge = $this->settings->verificationMaxAgeDays();
        $stale = fn (?CarbonInterface $checkedAt): bool => $checkedAt !== null && $checkedAt->lt(now()->subDays($maxAge));

        $vatApplies = $application->vat_number !== null;
        $chApplies = $application->registration_number !== null;
        $vat = $vatApplies ? $this->latestVat($application) : null;
        $ch = $chApplies ? $this->latestCompany($application) : null;
        $form = LegalForm::tryFrom((string) $application->legal_form);
        $mustBeKnown = $form?->requiresCompaniesHouseNumber() === true;

        $warnings = [];
        $refusal = null;

        if ($vatApplies) {
            if ($vat === null || $vat->outcome === VatCheckOutcome::Unchecked->value) {
                $warnings[] = VerificationWarning::VatNotChecked;
            } elseif ($vat->outcome === VatCheckOutcome::NotFound->value) {
                $warnings[] = VerificationWarning::VatNotFound;
            } elseif ($stale($vat->checked_at)) {
                $warnings[] = VerificationWarning::VatStale;
            }
        }

        if ($chApplies) {
            if ($ch === null || $ch->outcome === CompaniesHouseCheckOutcome::Unchecked->value) {
                $warnings[] = VerificationWarning::CompaniesHouseNotChecked;
            } elseif ($ch->outcome === CompaniesHouseCheckOutcome::NotFound->value) {
                if ($mustBeKnown) {
                    $refusal = "Companies House has no company {$ch->company_number}. A limited company or LLP cannot be approved without one.";
                } else {
                    $warnings[] = VerificationWarning::CompaniesHouseNotFound;
                }
            } else {
                $status = (string) $ch->company_status;
                if ($mustBeKnown && CompaniesHouseStatus::refusesApproval($status)) {
                    $refusal = "Companies House shows company {$ch->company_number} as \"{$status}\". A limited company or LLP in that state cannot be approved.";
                } elseif ($status !== CompaniesHouseStatus::ACTIVE) {
                    $warnings[] = VerificationWarning::CompaniesHouseNotActive;
                }
                if (! $this->typeMatches($form, $ch->company_type)) {
                    $warnings[] = VerificationWarning::CompaniesHouseTypeMismatch;
                }
                if ($stale($ch->checked_at)) {
                    $warnings[] = VerificationWarning::CompaniesHouseStale;
                }
            }
        }

        return new VerificationAssessment(
            $vatApplies, $chApplies, $vat, $ch,
            $stale($vat?->checked_at), $stale($ch?->checked_at),
            $refusal, $warnings,
        );
    }

    public function latestVat(B2bApplication $application): ?VatNumberCheck
    {
        return VatNumberCheck::query()->where('b2b_application_id', $application->id)
            ->orderByDesc('checked_at')->orderByDesc('id')->first();
    }

    public function latestCompany(B2bApplication $application): ?CompaniesHouseCheck
    {
        return CompaniesHouseCheck::query()->where('b2b_application_id', $application->id)
            ->orderByDesc('checked_at')->orderByDesc('id')->first();
    }

    /**
     * 02 §25.5: the declared legal form against Companies House's type. No
     * expectation for a sole trader, "other" or an unclassified row.
     */
    private function typeMatches(?LegalForm $form, ?string $type): bool
    {
        if ($type === null) {
            return true;
        }

        return match ($form) {
            LegalForm::Llp => $type === 'llp',
            LegalForm::LimitedCompany => in_array($type, ['ltd', 'plc'], true) || str_starts_with($type, 'private-'),
            LegalForm::Partnership => in_array($type, ['limited-partnership', 'scottish-partnership'], true),
            default => true,
        };
    }

    private function recordVat(B2bApplication $application, string $vatNumber, VatCheckAuthority $authority, VatLookup $lookup, ?int $requestedByUserId): void
    {
        VatNumberCheck::query()->create([
            'b2b_application_id' => $application->id,
            'vat_number' => $vatNumber,
            'authority' => $authority->value,
            'outcome' => $lookup->outcome->value,
            'registered_name' => $lookup->name,
            'registered_address' => $lookup->address,
            'consultation_number' => $lookup->consultationNumber,
            'processed_at' => $lookup->processedAt,
            'failure_reason' => $lookup->failure?->value,
            'requested_by_user_id' => $requestedByUserId,
            // The application clock, not the database's transaction-start
            // now(): the job compares it with its own dispatch time.
            'checked_at' => now(),
        ]);
    }

    private function recordCompany(B2bApplication $application, string $companyNumber, CompanyLookup $lookup, ?int $requestedByUserId): void
    {
        CompaniesHouseCheck::query()->create([
            'b2b_application_id' => $application->id,
            'company_number' => $companyNumber,
            'outcome' => $lookup->outcome->value,
            'company_status' => $lookup->status,
            'company_type' => $lookup->type,
            'registered_name' => $lookup->name,
            'registered_office' => $lookup->office,
            'incorporated_on' => $lookup->incorporatedOn,
            'failure_reason' => $lookup->failure?->value,
            'requested_by_user_id' => $requestedByUserId,
            'checked_at' => now(),
        ]);
    }

    /** Our own VAT number with the given prefix, if configured (§21.3, §25.4). */
    private function sellerNumber(string $key, string $prefix): ?string
    {
        $value = SystemConfiguration::query()->where('config_key', $key)->where('scope', 'global')->value('value_text');
        $normalised = is_string($value) ? strtoupper((string) preg_replace('/[\s.\-]/', '', $value)) : '';

        return preg_match('/^'.$prefix.'\d{9}(\d{3})?$/', $normalised) === 1 ? $normalised : null;
    }
}
