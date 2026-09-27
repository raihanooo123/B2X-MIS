<?php

namespace App\Domain\Accounts;

use App\Models\B2bApplication;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;

/**
 * 05.2 §5.2: possible duplicates of an application, for the reviewer only
 * — never shown to the applicant and never a reason to refuse on their
 * own ("flagged for the reviewer, never auto-rejected").
 */
final class ApplicationDuplicates
{
    /** @return list<string> one line per flag */
    public function flags(B2bApplication $application): array
    {
        $flags = [];

        // 02 §25.4: a Northern Ireland business's XI and GB numbers share
        // their nine digits, so the match is on the core, not the string.
        if ($application->vat_number !== null) {
            $core = substr($application->vat_number, 2, 9);
            $sameCore = 'substring(vat_number from 3 for 9) = ?';
            foreach (Company::query()->whereRaw($sameCore, [$core])->get(['name', 'account_code', 'status', 'vat_number']) as $company) {
                $flags[] = "VAT number matches account {$company->name} ({$company->account_code}, {$company->status})".self::viaPrefix($company->vat_number, $application->vat_number).'.';
            }
            foreach ($this->otherApplications($application)->whereRaw($sameCore, [$core])->get(['public_id', 'company_name', 'status', 'vat_number']) as $other) {
                $flags[] = "VAT number also on application {$other->public_id} for {$other->company_name} ({$other->status})".self::viaPrefix($other->vat_number, $application->vat_number).'.';
            }
        }

        if ($application->registration_number !== null) {
            foreach (Company::query()->where('registration_number', $application->registration_number)->get(['name', 'account_code', 'status']) as $company) {
                $flags[] = "Company number matches account {$company->name} ({$company->account_code}, {$company->status}).";
            }
        }

        // Trigram similarity through companies_name_trgm_idx (02 §4.3).
        $similar = Company::query()
            ->whereRaw('name % ?', [$application->company_name])
            ->orderByRaw('similarity(name, ?) DESC', [$application->company_name])
            ->limit(5)
            ->get(['name', 'account_code', 'status']);
        foreach ($similar as $company) {
            $flags[] = "Name is similar to account {$company->name} ({$company->account_code}, {$company->status}).";
        }

        $openFromEmail = $this->otherApplications($application)
            ->where('contact_email', $application->contact_email)
            ->whereIn('status', B2bApplication::OPEN_STATUSES)
            ->count();
        if ($openFromEmail > 0) {
            $flags[] = "Another open application uses this contact email ({$openFromEmail}).";
        }

        return array_values(array_unique($flags));
    }

    private static function viaPrefix(?string $theirs, string $ours): string
    {
        return $theirs !== null && $theirs !== $ours ? ", as {$theirs}" : '';
    }

    /** @return Builder<B2bApplication> */
    private function otherApplications(B2bApplication $application): Builder
    {
        return B2bApplication::query()->whereKeyNot($application->id);
    }
}
