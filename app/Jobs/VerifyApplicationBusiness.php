<?php

namespace App\Jobs;

use App\Domain\Accounts\BusinessVerification;
use App\Models\B2bApplication;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;

/**
 * 02 §25.4–25.5: checks a trade application's VAT number and Companies
 * House number, after it has been filed (or when a reviewer re-runs them).
 * Three retries — 10 s, 60 s, 300 s — for a transient failure, and only
 * the final result is written. On the sync queue, or dispatched directly,
 * there is no retry: the first attempt is final.
 */
class VerifyApplicationBusiness implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public readonly string $dispatchedAt;

    public function __construct(
        public readonly int $applicationId,
        public readonly ?int $requestedByUserId = null,
    ) {
        // Microseconds, so a re-run in the same second as the automatic
        // check never mistakes that check's row for its own.
        $this->dispatchedAt = CarbonImmutable::now()->format('Y-m-d\TH:i:s.uP');
        $this->afterCommit();
    }

    public function handle(BusinessVerification $verification): void
    {
        $application = B2bApplication::query()->find($this->applicationId);
        if ($application === null) {
            return;
        }

        $final = $this->job === null || $this->job instanceof SyncJob || $this->attempts() >= $this->tries;
        $retry = $verification->run($application, $this->requestedByUserId, $final, CarbonImmutable::parse($this->dispatchedAt));

        if ($retry) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);
        }
    }
}
