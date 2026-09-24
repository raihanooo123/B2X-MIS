<?php

namespace App\Console\Commands;

use App\Domain\Ordering\OrderPayments;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Finds orders whose card payment was captured at the gateway but which
 * are still recorded `unpaid` — the drift a stale event cache produced
 * before capture marked orders paid in its own transaction.
 *
 * Reports by default. `--fix` marks each one paid, deliberately and with
 * a log line per order: 02 §11.4 — drift is never auto-corrected, a person
 * runs the correction after the cause is understood.
 */
class ReconcileCardPayments extends Command
{
    protected $signature = 'billing:reconcile-card-payments {--fix : Mark each drifted order paid}';

    protected $description = 'Find (and with --fix, correct) card orders captured at Stripe but recorded unpaid';

    public function handle(): int
    {
        $drifted = Order::query()
            ->where('payment_status', 'unpaid')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('payments')
                ->whereColumn('payments.order_id', 'orders.id')
                ->where('payments.gateway', 'stripe')
                ->where('payments.type', 'payment')
                ->where('payments.status', 'captured'))
            ->orderBy('id')
            ->get(['id', 'order_number', 'total_gross_minor']);

        if ($drifted->isEmpty()) {
            $this->info('No drift: every captured card payment\'s order is recorded paid.');

            return self::SUCCESS;
        }

        $this->table(['Order', 'Total (minor)'], $drifted->map(fn (Order $o) => [$o->order_number, $o->total_gross_minor])->all());

        if (! $this->option('fix')) {
            $this->warn("{$drifted->count()} order(s) captured at the gateway but recorded unpaid. Re-run with --fix to correct them.");

            return self::FAILURE;
        }

        foreach ($drifted as $order) {
            if (OrderPayments::markPaid($order->id)) {
                Log::warning('Reconciliation: card order marked paid after capture drift.', ['order' => $order->order_number]);
            }
        }

        $this->info("Marked {$drifted->count()} order(s) paid.");

        return self::SUCCESS;
    }
}
