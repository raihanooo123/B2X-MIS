<?php

namespace App\Domain\Accounts\Verification;

use App\Domain\Accounts\VerificationFailureReason;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The verification clients never throw: an outage is evidence too, and is
 * recorded as `unchecked` with a reason (02 §25.4). Every request has the
 * same short timeout, so a slow service never holds a worker for long.
 */
trait FailsSoftly
{
    protected function http(): PendingRequest
    {
        return Http::timeout((int) config('services.verification.timeout_seconds', 5))
            ->connectTimeout((int) config('services.verification.timeout_seconds', 5))
            ->acceptJson();
    }

    protected function failureFor(Throwable $exception): VerificationFailureReason
    {
        if ($exception instanceof ConnectionException) {
            return str_contains(strtolower($exception->getMessage()), 'timed out')
                ? VerificationFailureReason::Timeout
                : VerificationFailureReason::Unavailable;
        }

        return VerificationFailureReason::UnexpectedResponse;
    }

    /** 429 → rate-limited; 5xx → unavailable; anything else unexpected. */
    protected function failureForStatus(Response $response): VerificationFailureReason
    {
        return match (true) {
            $response->status() === 429 => VerificationFailureReason::RateLimited,
            $response->status() === 504 => VerificationFailureReason::Timeout,
            $response->serverError() => VerificationFailureReason::Unavailable,
            default => VerificationFailureReason::UnexpectedResponse,
        };
    }
}
