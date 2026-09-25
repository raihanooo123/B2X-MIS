<?php

namespace App\Domain\Billing\Documents;

use App\Domain\Documents\PdfDocument;

/**
 * An issued invoice or receipt, as the renderer prints it. Built by
 * InvoiceDocumentBuilder; the payload shape is the contract with the PDF
 * worker's `invoice` template (docs/12-pdf-worker.md).
 */
final readonly class InvoiceDocument implements PdfDocument
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private array $payload) {}

    public function template(): string
    {
        return 'invoice';
    }

    public function toArray(): array
    {
        return $this->payload;
    }
}
