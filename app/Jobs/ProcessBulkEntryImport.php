<?php

namespace App\Jobs;

use App\Domain\Ordering\BulkEntry\BulkEntryImports;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reconciles an import of more than 500 rows off the request (05.1 §7.2).
 * Works only from the import's captured company and user — never the
 * worker's ambient context.
 */
class ProcessBulkEntryImport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function __construct(public readonly int $importId) {}

    public function uniqueId(): string
    {
        return (string) $this->importId;
    }

    public function handle(BulkEntryImports $imports): void
    {
        $imports->process($this->importId);
    }
}
