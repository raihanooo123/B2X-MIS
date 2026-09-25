<?php

namespace App\Domain\Warehouse\Events;

/**
 * 05.5 §5.3: an explicit batch reallocation. Until the audit log exists
 * (02 §15), its record is the pair of movements carrying actor, reason
 * code `batch_substitution` and a note naming both batches.
 */
final class BatchSubstituted
{
    public function __construct(
        public readonly int $releasedAllocationId,
        public readonly int $newAllocationId,
        public readonly int $fromBatchId,
        public readonly int $toBatchId,
        public readonly ?int $actorUserId,
        public readonly string $reason,
    ) {}
}
