<?php

namespace App\Domain\Reference;

use App\Domain\Reference\Exceptions\MustRunInsideTransactionException;
use App\Domain\Reference\Exceptions\UnknownNumberSequenceException;
use App\Models\NumberSequence;
use Illuminate\Support\Facades\DB;

/**
 * Doc 02 §11.3 — gapless document numbering. `order_number`,
 * `invoice_number`, `rma_number`, `credit_note_number`, `quote_number`
 * and `po_number` must never gap for accounting, which rules out a
 * native Postgres sequence (non-transactional, gaps on rollback — the
 * doc's own words: "correct behaviour for surrogate keys and
 * unacceptable for a document series HMRC may inspect"). This
 * table-backed counter is locked with `SELECT ... FOR UPDATE` and
 * incremented inside the SAME transaction that creates the document, so
 * a rolled-back document consumes no number — verified live in this
 * class's test suite, not just asserted.
 *
 * Contract for callers:
 *  - MUST call next() from inside your own document-creation
 *    transaction (enforced below — see MustRunInsideTransactionException).
 *    This service deliberately does not wrap its own transaction; doing
 *    so would let the number be consumed independently of whether the
 *    document itself is ever actually created.
 *  - SHOULD call it LAST, after all business validation, per §11.3:
 *    "The lock is taken last in the transaction, after all business
 *    validation, to minimise hold time." That ordering can only be the
 *    caller's responsibility — this service has no visibility into what
 *    else the caller's transaction is doing.
 *  - Sequence rows are provisioned explicitly (see
 *    UnknownNumberSequenceException) — this service never silently
 *    starts a new series for an unrecognised key.
 */
final class NumberSequenceService
{
    /**
     * @throws MustRunInsideTransactionException
     * @throws UnknownNumberSequenceException
     */
    public function next(string $keyName): string
    {
        if (DB::transactionLevel() === 0) {
            throw new MustRunInsideTransactionException($keyName);
        }

        $sequence = NumberSequence::query()->lockForUpdate()->find($keyName);

        if ($sequence === null) {
            throw new UnknownNumberSequenceException($keyName);
        }

        $formatted = $sequence->prefix.str_pad((string) $sequence->next_value, $sequence->padding, '0', STR_PAD_LEFT);

        $sequence->increment('next_value');

        return $formatted;
    }
}
