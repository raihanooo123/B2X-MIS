<?php

namespace App\Jobs;

use App\Domain\Documents\DocumentRenders;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Renders and archives one document version off the request (07 P25:
 * 3 s queued, on its own `documents` queue so its worker count bounds
 * Chromium concurrency). Unique per render while queued, so a repeated
 * "prepare" does not start a second browser for the same document. A
 * browser failure is retried with backoff; a crash mid-render is
 * recovered by the retry (DocumentRenders::render accepts `rendering`).
 */
class RenderDocument implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public int $timeout = 120;

    public function __construct(public readonly int $renderId)
    {
        $this->onQueue((string) config('documents.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->renderId;
    }

    public function handle(DocumentRenders $renders): void
    {
        $renders->render($this->renderId);
    }
}
