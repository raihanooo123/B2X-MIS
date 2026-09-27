<?php

namespace App\Domain\Accounts\Verification;

use App\Domain\Accounts\VerificationFailureReason;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The EU's VIES REST API (02 §25.4): Northern Ireland `XI` numbers. No
 * credentials. A member state can be down on its own, which VIES reports
 * in the body rather than the status code. Quoting our own `XI` number
 * (`seller.xi_vat_number`) returns a request identifier.
 */
final class ViesVatClient
{
    use FailsSoftly;

    public function lookup(string $vatNumber, ?string $requesterVatNumber): VatLookup
    {
        $body = ['countryCode' => substr($vatNumber, 0, 2), 'vatNumber' => substr($vatNumber, 2)];
        if ($requesterVatNumber !== null) {
            $body += ['requesterMemberStateCode' => substr($requesterVatNumber, 0, 2), 'requesterNumber' => substr($requesterVatNumber, 2)];
        }

        try {
            $response = $this->http()->post(rtrim((string) config('services.vies.base_url'), '/').'/check-vat-number', $body);
        } catch (Throwable $exception) {
            return VatLookup::unchecked($this->failureFor($exception));
        }

        $error = $this->errorCode($response->json());
        if ($error !== null) {
            return VatLookup::unchecked(match ($error) {
                'MS_UNAVAILABLE', 'SERVICE_UNAVAILABLE' => VerificationFailureReason::Unavailable,
                'MS_MAX_CONCURRENT_REQ', 'GLOBAL_MAX_CONCURRENT_REQ' => VerificationFailureReason::RateLimited,
                'TIMEOUT' => VerificationFailureReason::Timeout,
                default => VerificationFailureReason::UnexpectedResponse,
            });
        }
        if (! $response->successful()) {
            return VatLookup::unchecked($this->failureForStatus($response));
        }

        $valid = $response->json('valid');
        $requestDate = $response->json('requestDate');
        if (! is_bool($valid) || ! is_string($requestDate)) {
            return VatLookup::unchecked(VerificationFailureReason::UnexpectedResponse);
        }
        if (! $valid) {
            return VatLookup::notFound(CarbonImmutable::parse($requestDate));
        }

        $address = $this->disclosed($response->json('address'));
        $identifier = $response->json('requestIdentifier');

        return VatLookup::valid(
            $this->disclosed($response->json('name')),
            $address === null ? null : ['text' => $address],
            is_string($identifier) && $identifier !== '' ? $identifier : null,
            CarbonImmutable::parse($requestDate),
        );
    }

    /** VIES reports errors as `userError` or in `errorWrappers`. */
    private function errorCode(mixed $json): ?string
    {
        if (! is_array($json)) {
            return null;
        }
        $userError = $json['userError'] ?? null;
        if (is_string($userError) && ! in_array($userError, ['VALID', 'INVALID'], true)) {
            return $userError;
        }
        $wrapped = $json['errorWrappers'][0]['error'] ?? null;

        return is_string($wrapped) ? $wrapped : null;
    }

    /** Member states that withhold a detail return `---` (stored as NULL). */
    private function disclosed(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' && trim($value) !== '---' ? trim($value) : null;
    }
}
