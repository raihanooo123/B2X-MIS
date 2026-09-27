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

        if ($application->vat_number !== null) {
            foreach (Company::query()->where('vat_number', $application->vat_number)->get(['name', 'account_code', 'status']) as $company) {
                $flags[] = "VAT number matches account {$company->name} ({$company->account_code}, {$company->status}).";
            }
            foreach ($this->otherApplications($application)->where('vat_number', $application->vat_number)->get(['public_id', 'company_name', 'status']) as $other) {
                $flags[] = "VAT number also on application {$other->public_id} for {$other->company_name} ({$other->status}).";
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

    /** @return Builder<B2bApplication> */
    private function otherApplications(B2bApplication $application): Builder
    {
        return B2bApplication::query()->whereKeyNot($application->id);
    }
}
