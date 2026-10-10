<?php

namespace App\Domain\Billing\Documents;

use App\Domain\Documents\PdfDocument;

/** An account statement's fixed figures, as the `statement` print template lays them out. */
final readonly class StatementDocument implements PdfDocument
{
    /** @param array<string, mixed> $payload */
    public function __construct(private array $payload) {}

    public function template(): string
    {
        return 'statement';
    }

    public function toArray(): array
    {
        return $this->payload;
    }
}
