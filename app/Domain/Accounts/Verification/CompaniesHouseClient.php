<?php

namespace App\Domain\Accounts\Verification;

use App\Domain\Accounts\VerificationFailureReason;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The Companies House public data API, company profile (02 §25.5). The
 * API key is the HTTP basic-auth user name, with an empty password.
 */
final class CompaniesHouseClient
{
    use FailsSoftly;

    public function lookup(string $companyNumber): CompanyLookup
    {
        $key = config('services.companies_house.key');
        if (! is_string($key) || $key === '') {
            return CompanyLookup::unchecked(VerificationFailureReason::NotConfigured);
        }

        try {
            $response = $this->http()
                ->withBasicAuth($key, '')
                ->get(rtrim((string) config('services.companies_house.base_url'), '/').'/company/'.$companyNumber);
        } catch (Throwable $exception) {
            return CompanyLookup::unchecked($this->failureFor($exception));
        }

        if ($response->status() === 404) {
            return CompanyLookup::notFound();
        }
        if ($response->status() === 401) {
            return CompanyLookup::unchecked(VerificationFailureReason::NotConfigured);
        }
        if (! $response->successful()) {
            return CompanyLookup::unchecked($this->failureForStatus($response));
        }

        $status = $response->json('company_status');
        $name = $response->json('company_name');
        if (! is_string($status) || $status === '' || ! is_string($name) || $name === '') {
            return CompanyLookup::unchecked(VerificationFailureReason::UnexpectedResponse);
        }
        $type = $response->json('type');
        $office = $response->json('registered_office_address');
        $created = $response->json('date_of_creation');

        return CompanyLookup::found(
            $status,
            is_string($type) ? $type : null,
            $name,
            is_array($office) ? $office : null,
            is_string($created) ? CarbonImmutable::parse($created) : null,
        );
    }
}
