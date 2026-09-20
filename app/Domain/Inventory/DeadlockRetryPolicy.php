<?php

namespace App\Domain\Inventory;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Doc 04 §4.5 — the whole transaction is retried, never resumed, on
 * SQLSTATE 40P01 (deadlock detected) or 55P03 (lock not available).
 * 3 retries, exponential backoff with jitter (50 ms, 150 ms, 400 ms
 * ± 30%) — 4 attempts total. Any other exception, including a retryable
 * SQLSTATE seen after the 3rd retry, surfaces immediately: exhausted
 * retries are a user-facing "please try again" and an alert, not a
 * silent failure. §4.3's consistent lock ordering is what should make
 * this rare — a sustained rise in retry count means that rule has been
 * violated somewhere, which is why each retry is logged.
 */
final class DeadlockRetryPolicy
{
    private const RETRYABLE_SQLSTATES = ['40P01', '55P03'];

    private const BACKOFF_MS = [50, 150, 400];

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $operation
     * @return TReturn
     */
    public function run(Closure $operation, string $label): mixed
    {
        $retries = 0;

        while (true) {
            try {
                return $operation();
            } catch (QueryException $e) {
                $sqlState = $this->sqlState($e);

                if (! in_array($sqlState, self::RETRYABLE_SQLSTATES, true) || $retries >= count(self::BACKOFF_MS)) {
                    throw $e;
                }

                $delayMs = self::BACKOFF_MS[$retries];
                $jitteredMs = $delayMs + $delayMs * random_int(-30, 30) / 100;
                $retries++;

                Log::warning("{$label}: retrying transaction after {$sqlState} (attempt {$retries} of ".count(self::BACKOFF_MS).')');

                usleep((int) round($jitteredMs * 1000));
            }
        }
    }

    private function sqlState(QueryException $e): ?string
    {
        return $e->errorInfo[0] ?? null;
    }
}
