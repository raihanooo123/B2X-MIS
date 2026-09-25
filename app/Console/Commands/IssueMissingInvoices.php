<?php

namespace App\Console\Commands;

use App\Domain\Billing\InvoiceService;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finds orders that 05.5 §7.3 says should already be documented but are
 * not. Invoices are issued after commit, and a failed issue (no seller VAT
 * number, no number series) is logged but leaves the order and payment
 * standing. The cases:
 *
 *   - a trade BACS or prepay order, which is invoiced at placement;
 *   - a prepaid order with a captured payment, invoiced or receipted at
 *     capture.
 *
 * Reports by default. `--fix` issues each missing document: 02 §11.4 —
 * a person runs the correction once the cause is understood.
 */
class IssueMissingInvoices extends Command
{
    protected $signature = 'billing:issue-missing-invoices {--fix : Issue each missing invoice or receipt}';

    protected $description = 'Find (and with --fix, issue) invoices and receipts that should exist under 05.5 §7.3 but do not';

    public function handle(InvoiceService $invoices): int
    {
        $missing = Order::query()
            ->whereNotIn('status', ['draft', 'awaiting_approval', 'cancelled'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('invoices')
                ->whereColumn('invoices.order_id', 'orders.id')
                ->whereNull('invoices.shipment_id')
                ->where('invoices.status', '<>', 'void'))
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->whereNotNull('company_id')->whereIn('payment_method', ['bacs', 'prepay']))
                ->orWhere(fn (Builder $q) => $q
                    ->where(fn (Builder $q) => $q->whereNull('payment_method')->orWhere('payment_method', '<>', 'on_account'))
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('payments')
                        ->whereColumn('payments.order_id', 'orders.id')
                        ->where('payments.type', 'payment')
                        ->where('payments.status', 'captured'))))
            ->orderBy('id')
            ->get(['id', 'order_number', 'company_id', 'payment_method']);

        if ($missing->isEmpty()) {
            $this->info('No missing invoices or receipts.');

            return self::SUCCESS;
        }

        $this->table(['Order', 'Kind', 'Payment method'], $missing->map(fn (Order $o) => [
            $o->order_number,
            $o->company_id === null ? 'receipt' : 'invoice',
            $o->payment_method ?? '—',
        ])->all());

        if (! $this->option('fix')) {
            $this->warn("{$missing->count()} order(s) missing an invoice or receipt. Re-run with --fix to issue them.");

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($missing as $order) {
            try {
                $invoice = $invoices->issueForOrder($order->id);
                Log::warning('Reconciliation: missing invoice issued.', ['order' => $order->order_number, 'invoice' => $invoice->invoice_number]);
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$order->order_number}: {$e->getMessage()}");
            }
        }

        $this->info('Issued '.($missing->count() - $failed)." of {$missing->count()}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
