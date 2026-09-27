<?php

namespace App\Domain\Accounts\Verification;

use App\Domain\Accounts\VerificationFailureReason;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * HMRC "Check a UK VAT number" API v2.0 (02 §25.4): `GB` numbers.
 * Application-restricted OAuth — a client-credentials token, cached until
 * shortly before it expires. Quoting our own VRN (`seller.vat_number`)
 * returns a consultation number, HMRC's proof the check was made.
 */
final class HmrcVatClient
{
    use FailsSoftly;

    private const TOKEN_CACHE_KEY = 'verification:hmrc:token';

    public function lookup(string $vatNumber, ?string $requesterVatNumber): VatLookup
    {
        $baseUrl = rtrim((string) config('services.hmrc_vat.base_url'), '/');
        $clientId = config('services.hmrc_vat.client_id');
        $secret = config('services.hmrc_vat.client_secret');
        if (! is_string($clientId) || $clientId === '' || ! is_string($secret) || $secret === '') {
            return VatLookup::unchecked(VerificationFailureReason::NotConfigured);
        }

        try {
            $token = $this->token($baseUrl, $clientId, $secret);
            if ($token instanceof VatLookup) {
                return $token;
            }

            $path = '/organisations/vat/check-vat-number/lookup/'.substr($vatNumber, 2)
                .($requesterVatNumber === null ? '' : '/'.substr($requesterVatNumber, 2));
            $response = $this->http()
                ->withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.hmrc.2.0+json'])
                ->get($baseUrl.$path);
        } catch (Throwable $exception) {
            return VatLookup::unchecked($this->failureFor($exception));
        }

        if ($response->status() === 404) {
            return VatLookup::notFound();
        }
        if (! $response->successful()) {
            return VatLookup::unchecked($this->failureForStatus($response));
        }

        $name = $response->json('target.name');
        $processed = $response->json('processingDate');
        if (! is_string($name) || $name === '' || ! is_string($processed)) {
            return VatLookup::unchecked(VerificationFailureReason::UnexpectedResponse);
        }
        $address = $response->json('target.address');
        $consultation = $response->json('consultationNumber');

        return VatLookup::valid(
            $name,
            is_array($address) ? $address : null,
            is_string($consultation) && $consultation !== '' ? $consultation : null,
            CarbonImmutable::parse($processed),
        );
    }

    /** The bearer token, or the reason there is none. */
    private function token(string $baseUrl, string $clientId, string $secret): string|VatLookup
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = $this->http()->asForm()->post($baseUrl.'/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $secret,
        ]);
        if (in_array($response->status(), [400, 401, 403], true)) {
            return VatLookup::unchecked(VerificationFailureReason::NotConfigured);
        }
        if (! $response->successful()) {
            return VatLookup::unchecked($this->failureForStatus($response));
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            return VatLookup::unchecked(VerificationFailureReason::UnexpectedResponse);
        }
        $expires = $response->json('expires_in');
        Cache::put(self::TOKEN_CACHE_KEY, $token, max(60, (is_numeric($expires) ? (int) $expires : 3600) - 60));

        return $token;
    }
}
