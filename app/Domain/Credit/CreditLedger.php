<?php

namespace App\Domain\Credit;

use App\Domain\Ordering\PaymentMethod;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 02 §31.1, 05.4 §15.3 — the append-only account-credit ledger.
 *
 * One `account_credit_events` row per domain action, keyed by the action
 * itself (`credit-note:{id}`, `payout:{public_id}`), never by an HTTP
 * retry key: the non-partitioned registry gives global idempotency that a
 * partition-local UNIQUE cannot. A replay with the same payload is a
 * no-op; a different payload under the same key is a conflict. Movements
 * carry the event's exact `occurred_at` and fixed entry numbers, and
 * `companies.account_balance_minor` is moved in the same transaction.
 * Both tables reject UPDATE/DELETE/TRUNCATE (02 §31.8).
 *
 * Every writer holds the `companies` row first; invoice rows follow in id
 * order (02 §31.1 lock extension).
 */
final class CreditLedger
{
    /**
     * Records one event and its movements. False when this exact event was
     * already recorded.
     *
     * @param  list<array{type: CreditMovementType, amount: int, invoice_id?: int, credit_note_id?: int, order_id?: int, payment_id?: int, reason?: string, note?: string}>  $entries
     *
     * @throws CreditRefused
     */
    public function append(Company $company, string $key, array $entries, ?int $actorUserId = null): bool
    {
        if (DB::transactionLevel() < 1 || $entries === []) {
            throw new InvalidArgumentException('Ledger writes need entries, inside the transaction holding the company lock.');
        }

        $hash = hash('sha256', $company->id.'|'.json_encode(array_map(fn (array $e): array => ['type' => $e['type']->value] + $e, $entries), JSON_THROW_ON_ERROR));
        $existingHash = DB::table('account_credit_events')->where('event_key', $key)->value('payload_hash');
        if ($existingHash !== null) {
            if ($existingHash !== $hash) {
                throw new CreditRefused('event_conflict', 'This account operation was already recorded with different details.', 409);
            }

            return false;
        }

        $at = now();
        $eventId = (int) DB::table('account_credit_events')->insertGetId([
            'event_key' => $key,
            'company_id' => $company->id,
            'event_kind' => $entries[0]['type']->value,
            'payload_hash' => $hash,
            'occurred_at' => $at,
            'actor_user_id' => $actorUserId,
        ]);

        $balance = (int) Company::query()->where('id', $company->id)->value('account_balance_minor');
        foreach ($entries as $i => $entry) {
            if ($entry['amount'] === 0) {
                throw new InvalidArgumentException('A ledger movement cannot be zero.');
            }
            $balance += $entry['amount'];
            if ($balance < 0) {
                throw new CreditRefused('balance_unavailable', 'The account balance has changed. Review the amount and try again.', 409);
            }

            $referenceType = isset($entry['invoice_id']) ? 'invoice' : (isset($entry['order_id']) ? 'order' : null);
            DB::table('account_credit_movements')->insert([
                'occurred_at' => $at,
                'company_id' => $company->id,
                'event_id' => $eventId,
                'entry_no' => $i + 1,
                'movement_type' => $entry['type']->value,
                'amount_minor' => $entry['amount'],
                'balance_after_minor' => $balance,
                'reference_type' => $referenceType,
                'reference_id' => $entry['invoice_id'] ?? $entry['order_id'] ?? null,
                'credit_note_id' => $entry['credit_note_id'] ?? null,
                'order_id' => $entry['order_id'] ?? null,
                'payment_id' => $entry['payment_id'] ?? null,
                'reason_code' => $entry['reason'] ?? null,
                'note' => $entry['note'] ?? null,
                'actor_user_id' => $actorUserId,
            ]);
        }

        Company::query()->where('id', $company->id)->update(['account_balance_minor' => $balance, 'updated_at' => $at]);
        $company->account_balance_minor = $balance;

        return true;
    }

    /**
     * 05.4 §15.3: a trade credit note first extinguishes the debt on its
     * own invoice; only the excess becomes spendable balance. £120 against
     * £100 unpaid → +£120 credit_note, −£100 applied_to_invoice, invoice
     * credited +£100, balance +£20. Never both.
     *
     * Opens its own transaction (or joins the caller's). Replays do nothing.
     */
    public function settleCreditNote(int $creditNoteId, ?int $actorUserId = null): void
    {
        DB::transaction(function () use ($creditNoteId, $actorUserId): void {
            $peek = CreditNote::query()->findOrFail($creditNoteId, ['id', 'company_id']);
            if ($peek->company_id === null) {
                throw new InvalidArgumentException('A consumer credit note never becomes account balance (05.4 §15).');
            }
            $company = Company::query()->where('id', $peek->company_id)->lockForUpdate()->firstOrFail();
            $key = 'credit-note:'.$creditNoteId;
            if (DB::table('account_credit_events')->where('event_key', $key)->exists()) {
                return;
            }

            $note = CreditNote::query()->where('id', $creditNoteId)->firstOrFail();
            if ($note->status !== 'issued' || $note->total_gross_minor <= 0) {
                throw new CreditRefused('credit_note_not_issued', 'Only an issued credit note can be settled.');
            }

            // A note with no invoice credits no debt: all of it is balance.
            $invoices = Invoice::query()->where('id', $note->invoice_id ?? 0)->where('company_id', $company->id)
                ->orderBy('id')->lockForUpdate()->get();

            $remaining = $note->total_gross_minor;
            $entries = [['type' => CreditMovementType::CreditNote, 'amount' => $remaining, 'credit_note_id' => $note->id]];
            $allocations = [];
            foreach ($invoices as $invoice) {
                if (! in_array($invoice->status, CreditGate::UNPAID_STATUSES, true)) {
                    continue;
                }
                $amount = min($remaining, max(0, $invoice->total_gross_minor - $invoice->paid_minor - $invoice->credited_minor));
                if ($amount === 0) {
                    continue;
                }
                $allocations[] = [$invoice, $amount];
                $entries[] = ['type' => CreditMovementType::AppliedToInvoice, 'amount' => -$amount, 'credit_note_id' => $note->id, 'invoice_id' => $invoice->id];
                $remaining -= $amount;
            }

            $this->append($company, $key, $entries, $actorUserId);
            $eventId = (int) DB::table('account_credit_events')->where('event_key', $key)->value('id');

            foreach ($allocations as [$invoice, $amount]) {
                DB::table('credit_note_allocations')->insert([
                    'credit_note_id' => $note->id,
                    'invoice_id' => $invoice->id,
                    'event_id' => $eventId,
                    'amount_minor' => $amount,
                    'allocated_at' => now(),
                ]);
                $credited = $invoice->credited_minor + $amount;
                $invoice->forceFill([
                    'credited_minor' => $credited,
                    'status' => $credited + $invoice->paid_minor >= $invoice->total_gross_minor
                        ? ($invoice->paid_minor > 0 ? 'paid' : 'credited')
                        : $invoice->status,
                ])->save();

                // Only on-account invoices count in credit_used (PaymentAllocationService).
                $method = Order::query()->where('id', $invoice->order_id)->value('payment_method');
                if ($method === PaymentMethod::OnAccount->value) {
                    Company::query()->where('id', $company->id)->decrement('credit_used_minor', $amount);
                }
            }
        });
    }
}
