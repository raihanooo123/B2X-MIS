<?php

namespace App\Console\Commands;

use App\Domain\Notifications\Notifications;
use App\Models\Invoice;
use Illuminate\Console\Command;

/**
 * 05.2 §9, §11 — daily invoice reminders, for invoices on credit terms
 * (a `prepay` invoice is due on issue and chased by the order hold, not
 * by reminders; receipts are paid by definition):
 *
 *   - `invoice.due_soon` — unpaid, due within the next 3 days;
 *   - `invoice.overdue`  — unpaid and past `due_at` plus the grace period
 *     (default 3 days, 05.2 §9).
 *
 * Each is sent once per invoice: NotificationDispatcher's dedup key makes
 * re-running the command harmless. Moving an invoice's `status` to
 * `overdue` belongs to 05.2's ageing work, not here.
 */
class SendInvoiceReminders extends Command
{
    protected $signature = 'notifications:invoice-reminders';

    protected $description = 'Queue due-soon and overdue invoice reminders (05.2 §11)';

    private const DUE_SOON_DAYS = 3;

    private const GRACE_DAYS = 3;

    public function handle(Notifications $notifications): int
    {
        $unpaid = fn () => Invoice::query()
            ->whereNotNull('company_id')
            ->where('payment_terms', '<>', 'prepay')
            ->whereIn('status', ['issued', 'part_paid', 'overdue'])
            ->whereColumn('paid_minor', '<', 'total_gross_minor');

        $dueSoon = 0;
        $unpaid()->whereBetween('due_at', [now(), now()->addDays(self::DUE_SOON_DAYS)])
            ->orderBy('id')
            ->each(function (Invoice $invoice) use ($notifications, &$dueSoon) {
                $notifications->invoiceDueSoon($invoice);
                $dueSoon++;
            });

        $overdue = 0;
        $unpaid()->where('due_at', '<', now()->subDays(self::GRACE_DAYS))
            ->orderBy('id')
            ->each(function (Invoice $invoice) use ($notifications, &$overdue) {
                $notifications->invoiceOverdue($invoice);
                $overdue++;
            });

        $this->info("Checked {$dueSoon} due-soon and {$overdue} overdue invoice(s); reminders already sent are skipped.");

        return self::SUCCESS;
    }
}
