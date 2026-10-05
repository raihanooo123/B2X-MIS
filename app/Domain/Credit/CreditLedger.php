<?php
namespace App\Domain\Credit;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Every writer serialises on companies; events make financial retries durable. */
final class CreditLedger
{
    /**
     * Caller must own the transaction and company lock before invoice/stock/order locks.
     * @param list<array{type: string, amount: int, invoice_id?: int, credit_note_id?: int, order_id?: int, payment_id?: int, reason?: string}> $entries
     */
    public function append(Company $company, string $key, array $entries, ?int $actorId = null): bool
    {
        if (DB::transactionLevel() < 1 || $entries === []) { throw new InvalidArgumentException('Ledger writes require a company-locked transaction and entries.'); }
        $hash = hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR));
        $existing = DB::table('account_credit_events')->where('event_key', $key)->first();
        if ($existing !== null) {
            if ((int) $existing->company_id !== $company->id || $existing->payload_hash !== $hash) { throw new CreditRefused('event_conflict', 'This financial operation was already recorded with a different payload.', 409); }
            return false;
        }
        $at = now();
        $eventId = DB::table('account_credit_events')->insertGetId(['event_key' => $key, 'company_id' => $company->id,
            'event_kind' => $entries[0]['type'], 'payload_hash' => $hash, 'occurred_at' => $at, 'actor_user_id' => $actorId]);
        $balance = (int) Company::query()->where('id', $company->id)->value('account_balance_minor');
        foreach ($entries as $i => $entry) {
            if ($entry['amount'] === 0 || CreditMovementType::tryFrom($entry['type']) === null) { throw new InvalidArgumentException('Invalid ledger entry.'); }
            $balance += $entry['amount'];
            if ($balance < 0) { throw new CreditRefused('balance_unavailable', 'The account balance changed; review the amount again.', 409); }
            DB::table('account_credit_movements')->insert(['event_id' => $eventId, 'entry_no' => $i + 1,
                'company_id' => $company->id, 'occurred_at' => $at, 'movement_type' => $entry['type'],
                'amount_minor' => $entry['amount'], 'balance_after_minor' => $balance,
                'reference_type' => isset($entry['invoice_id']) ? 'invoice' : (isset($entry['order_id']) ? 'order' : null),
                'reference_id' => $entry['invoice_id'] ?? $entry['order_id'] ?? null,
                'credit_note_id' => $entry['credit_note_id'] ?? null, 'order_id' => $entry['order_id'] ?? null,
                'payment_id' => $entry['payment_id'] ?? null, 'reason_code' => $entry['reason'] ?? null, 'actor_user_id' => $actorId]);
        }
        Company::query()->where('id', $company->id)->update(['account_balance_minor' => $balance, 'updated_at' => $at]);
        $company->account_balance_minor = $balance;
        return true;
    }

    /** Apply a trade credit note to its original unpaid invoice(s); excess is spendable. */
    public function issueNote(int $noteId, ?int $actorId = null): void
    {
        $note = CreditNote::query()->findOrFail($noteId);
        if ($note->company_id === null) { throw new InvalidArgumentException('Consumers cannot receive account balance.'); }
        DB::transaction(function () use ($note, $actorId): void {
            $company = Company::query()->where('id', $note->company_id)->lockForUpdate()->firstOrFail();
            $key = 'credit-note:'.$note->id;
            if (DB::table('account_credit_events')->where('event_key', $key)->exists()) { return; }
            $note = CreditNote::query()->where('id', $note->id)->firstOrFail();
            if ($note->status !== 'issued') { throw new CreditRefused('note_not_issued', 'Only an issued credit note can be allocated.'); }
            $invoices = Invoice::query()->where('company_id', $company->id)->whereNotIn('status', ['void','credited'])
                ->when($note->invoice_id !== null, fn ($q) => $q->where('id', $note->invoice_id),
                    fn ($q) => $q->where('order_id', $note->order_id ?? 0))
                ->orderBy('id')->lockForUpdate()->get();
            $remaining = $note->total_gross_minor;
            if ($remaining <= 0) { return; }
            $entries = [['type' => 'credit_note', 'amount' => $remaining, 'credit_note_id' => $note->id]];
            $allocations = [];
            foreach ($invoices as $invoice) {
                $amount = min($remaining, max(0, $invoice->total_gross_minor - $invoice->paid_minor - $invoice->credited_minor));
                if ($amount === 0) { continue; }
                $allocations[] = [$invoice, $amount];
                $entries[] = ['type' => 'applied_to_invoice', 'amount' => -$amount, 'credit_note_id' => $note->id, 'invoice_id' => $invoice->id];
                $remaining -= $amount;
            }
            $this->append($company, $key, $entries, $actorId);
            $eventId = DB::table('account_credit_events')->where('event_key', $key)->value('id');
            foreach ($allocations as [$invoice, $amount]) {
                DB::table('credit_note_allocations')->insert(['credit_note_id' => $note->id, 'invoice_id' => $invoice->id,
                    'event_id' => $eventId, 'amount_minor' => $amount, 'allocated_at' => now()]);
                $credited = $invoice->credited_minor + $amount;
                $invoice->update(['credited_minor' => $credited, 'status' => $credited + $invoice->paid_minor >= $invoice->total_gross_minor ? 'credited' : ($invoice->paid_minor > 0 ? 'part_paid' : 'issued')]);
                Company::query()->where('id', $company->id)->decrement('credit_used_minor', $amount);
            }
        });
    }

    /** The caller has locked company before stock; consume only at a funded checkout. */
    public function applyToOrder(Company $company, Order $order, int $amount, ?int $actorId): void
    {
        if ($amount <= 0) { return; }
        $payment = Payment::query()->firstOrCreate(['gateway' => 'internal', 'gateway_reference' => 'account-credit:order:'.$order->id],
            ['company_id' => $company->id, 'order_id' => $order->id, 'type' => 'payment', 'status' => 'captured', 'amount_minor' => $amount, 'currency' => 'GBP', 'captured_at' => now()]);
        $this->append($company, 'account-credit:order:'.$order->id, [['type' => 'applied_to_order', 'amount' => -$amount, 'order_id' => $order->id, 'payment_id' => $payment->id]], $actorId);
        $order->forceFill(['account_credit_applied_minor' => $amount])->save();
    }

    public function reverseOrder(Company $company, Order $order): void
    {
        $amount = (int) $order->account_credit_applied_minor;
        if ($amount <= 0) { return; }
        $key = 'account-credit:cancel:'.$order->id;
        if ($this->append($company, $key, [['type' => 'reversal', 'amount' => $amount, 'order_id' => $order->id, 'reason' => 'unpaid_order_cancelled']])) {
            Payment::query()->where('order_id', $order->id)->where('gateway', 'internal')->where('type', 'payment')->update(['status' => 'voided']);
        }
    }
}
