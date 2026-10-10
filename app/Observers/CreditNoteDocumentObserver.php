<?php

namespace App\Observers;

use App\Domain\Billing\Documents\CreditNoteDocumentBuilder;
use App\Domain\Documents\DocumentRenders;
use App\Jobs\RenderDocument;
use App\Models\CreditNote;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 05.17 §3 — a credit note's printable payload is fixed when it is issued,
 * whichever service issued it (cancellation, partial cancellation, return,
 * invoice correction), then its PDF is queued. After commit, so a rolled
 * back note leaves nothing behind and its allocations are already visible.
 */
final class CreditNoteDocumentObserver
{
    public bool $afterCommit = true;

    public function created(CreditNote $note): void
    {
        try {
            $render = app(DocumentRenders::class)->capture(
                'credit_note',
                $note->id,
                $note->company_id ?? ($note->invoice === null ? null : $note->invoice->company_id) ?? ($note->order === null ? null : $note->order->company_id),
                (new CreditNoteDocumentBuilder)->build($note),
            );
            RenderDocument::dispatch($render->id);
        } catch (Throwable $e) {
            Log::error('Credit note document payload not captured.', ['credit_note_id' => $note->id, 'error' => $e::class]);
        }
    }
}
