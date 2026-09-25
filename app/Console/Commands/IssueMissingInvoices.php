<?php

namespace App\Console\Commands;

use App\Domain\Billing\InvoiceService;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Shipment;
use Closure;
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
 *     capture;
 *   - an on-account order at dispatch: each dispatched shipment under
 *     `invoicing.mode = per_shipment`, or the whole order once dispatched
 *     under `on_completion`.
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

        /** @var list<array{label: string, kind: string, method: string, issue: Closure(): ?Invoice}> $work */
        $work = [];
        foreach ($missing as $order) {
            $work[] = [
                'label' => $order->order_number,
                'kind' => $order->company_id === null ? 'receipt' : 'invoice',
                'method' => $order->payment_method ?? '—',
                'issue' => fn () => $invoices->issueForOrder($order->id),
            ];
        }
        foreach ($this->onAccountAtDispatch($invoices) as $item) {
            $work[] = $item;
        }

        if ($work === []) {
            $this->info('No missing invoices or receipts.');

            return self::SUCCESS;
        }

        $this->table(['Order', 'Kind', 'Payment method'], array_map(fn (array $w) => [$w['label'], $w['kind'], $w['method']], $work));

        if (! $this->option('fix')) {
            $this->warn(count($work).' document(s) missing. Re-run with --fix to issue them.');

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($work as $item) {
            try {
                $invoice = ($item['issue'])();
                Log::warning('Reconciliation: missing invoice issued.', ['for' => $item['label'], 'invoice' => $invoice?->invoice_number]);
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$item['label']}: {$e->getMessage()}");
            }
        }

        $this->info('Issued '.(count($work) - $failed).' of '.count($work).'.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * On-account orders invoiced at dispatch (05.5 §7.3) whose invoice
     * failed to issue: per shipment, or the whole order on completion.
     *
     * @return list<array{label: string, kind: string, method: string, issue: Closure(): ?Invoice}>
     */
    private function onAccountAtDispatch(InvoiceService $invoices): array
    {
        $noWholeOrderDocument = fn ($q) => $q->selectRaw('1')->from('invoices')
            ->whereColumn('invoices.order_id', 'orders.id')
            ->whereNull('invoices.shipment_id')
            ->where('invoices.status', '<>', 'void');

        $shipments = Shipment::query()
            ->join('orders', 'orders.id', '=', 'shipments.order_id')
            ->where('shipments.status', 'dispatched')
            ->where('orders.payment_method', 'on_account')
            ->whereNotNull('orders.company_id')
            ->whereNotExists($noWholeOrderDocument)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('invoices')
                ->whereColumn('invoices.shipment_id', 'shipments.id')
                ->where('invoices.status', '<>', 'void'))
            ->orderBy('shipments.dispatched_at')
            ->orderBy('shipments.id')
            ->get(['shipments.id', 'shipments.public_id', 'orders.order_number', 'orders.company_id', 'orders.status as order_status', 'orders.id as order_id']);

        $work = [];
        $completedOrders = [];
        foreach ($shipments as $shipment) {
            $companyId = (int) $shipment->getAttribute('company_id');
            $orderNumber = (string) $shipment->getAttribute('order_number');

            if ($invoices->invoicingMode($companyId) === InvoiceService::MODE_PER_SHIPMENT) {
                $shipmentId = $shipment->id;
                $work[] = ['label' => "{$orderNumber} / shipment {$shipment->public_id}", 'kind' => 'invoice', 'method' => 'on_account', 'issue' => fn () => $invoices->issueForShipment($shipmentId)];
            } elseif ($shipment->getAttribute('order_status') === 'dispatched') {
                $completedOrders[(int) $shipment->getAttribute('order_id')] = $orderNumber;
            }
        }

        foreach ($completedOrders as $orderId => $orderNumber) {
            $work[] = ['label' => $orderNumber, 'kind' => 'invoice', 'method' => 'on_account', 'issue' => fn () => $invoices->issueForOrder($orderId)];
        }

        return $work;
    }
}
