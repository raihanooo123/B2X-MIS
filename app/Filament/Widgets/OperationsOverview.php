<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\PurchaseOrderResource;
use App\Filament\Resources\TradeApplicationResource;
use App\Filament\Support\MoneyFormatter;
use App\Models\B2bApplication;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Dashboard headline counts. Each figure is shown only to the roles whose
 * policy lets them open the list behind it, and each query is one the
 * schema already indexes (02 §10): `orders_open_status_idx`,
 * `b2b_applications_queue_idx`, `invoices_unpaid_idx`.
 */
class OperationsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 10;

    protected static ?string $pollingInterval = null;

    /** The statuses `orders_open_status_idx` is partial on: placed, not yet fully dispatched. */
    private const OPEN_ORDER_STATUSES = ['awaiting_approval', 'pending_payment', 'confirmed', 'picking', 'part_dispatched'];

    /** Unpaid statuses, as `invoices_unpaid_idx` (02 §14.5). */
    private const UNPAID_INVOICE_STATUSES = ['issued', 'part_paid', 'overdue'];

    public static function canView(): bool
    {
        return self::canSeeOrders() || TradeApplicationResource::canViewAny() || PurchaseOrderResource::canViewAny()
            || InvoiceResource::canViewAny() || ProductResource::canViewAny();
    }

    protected function getStats(): array
    {
        return array_values(array_filter([
            self::canSeeOrders() ? $this->openOrders() : null,
            TradeApplicationResource::canViewAny() ? $this->tradeApplications() : null,
            InvoiceResource::canViewAny() ? $this->receivables() : null,
            PurchaseOrderResource::canViewAny() ? $this->purchaseOrders() : null,
            ProductResource::canViewAny() ? $this->catalogue() : null,
        ]));
    }

    private static function canSeeOrders(): bool
    {
        return Gate::allows('viewAny', Shipment::class) || Gate::allows('viewAny', Invoice::class);
    }

    private function openOrders(): Stat
    {
        $byStatus = Order::query()
            ->whereIn('status', self::OPEN_ORDER_STATUSES)
            ->selectRaw('status, COUNT(*) AS n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->map(fn (mixed $n): int => (int) $n);

        $toPick = ($byStatus['confirmed'] ?? 0) + ($byStatus['picking'] ?? 0) + ($byStatus['part_dispatched'] ?? 0);
        $waiting = ($byStatus['awaiting_approval'] ?? 0) + ($byStatus['pending_payment'] ?? 0);

        $stat = Stat::make('Open orders', (string) $byStatus->sum())
            ->description("{$toPick} to fulfil · {$waiting} awaiting approval or payment")
            ->descriptionIcon('heroicon-m-shopping-bag')
            ->color($toPick > 0 ? 'primary' : 'gray');

        return Gate::allows('viewAny', Shipment::class) ? $stat->url(route('warehouse.pick-list')) : $stat;
    }

    private function tradeApplications(): Stat
    {
        $open = B2bApplication::query()->whereIn('status', B2bApplication::OPEN_STATUSES);
        $count = (clone $open)->count();
        $oldest = (clone $open)->min('submitted_at');

        return Stat::make('Trade applications to review', (string) $count)
            ->description($oldest === null ? 'Queue is clear' : 'Oldest submitted '.Carbon::parse($oldest)->diffForHumans())
            ->descriptionIcon($count > 0 ? 'heroicon-m-clock' : 'heroicon-m-check-circle')
            ->color($count > 0 ? 'warning' : 'success')
            ->url(TradeApplicationResource::getUrl('index'));
    }

    private function receivables(): Stat
    {
        $row = Invoice::query()
            ->whereIn('status', self::UNPAID_INVOICE_STATUSES)
            ->whereNotNull('company_id')
            ->toBase()
            ->selectRaw("COALESCE(SUM(total_gross_minor - paid_minor), 0) AS outstanding_minor, COUNT(*) FILTER (WHERE status = 'overdue') AS overdue")
            ->first();

        // SUM over bigint is numeric in Postgres and arrives as a string of
        // digits; the integer cast never passes through a float.
        $outstanding = (int) ($row->outstanding_minor ?? 0);
        $overdue = (int) ($row->overdue ?? 0);

        return Stat::make('Trade receivables', (string) MoneyFormatter::minor($outstanding))
            ->description($overdue === 0 ? 'Nothing overdue' : "{$overdue} ".($overdue === 1 ? 'invoice' : 'invoices').' overdue')
            ->descriptionIcon($overdue === 0 ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
            ->color($overdue === 0 ? 'success' : 'danger')
            ->url(InvoiceResource::getUrl('index'));
    }

    private function purchaseOrders(): Stat
    {
        $byStatus = PurchaseOrder::query()
            ->whereIn('status', [...PurchaseOrder::RECEIVABLE_STATUSES, 'draft'])
            ->selectRaw('status, COUNT(*) AS n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->map(fn (mixed $n): int => (int) $n);

        $drafts = $byStatus['draft'] ?? 0;

        return Stat::make('Purchase orders due in', (string) ($byStatus->sum() - $drafts))
            ->description("{$drafts} ".($drafts === 1 ? 'draft' : 'drafts').' not yet sent')
            ->descriptionIcon('heroicon-m-truck')
            ->color('gray')
            ->url(PurchaseOrderResource::getUrl('index'));
    }

    private function catalogue(): Stat
    {
        $byStatus = Product::query()
            ->selectRaw('status, COUNT(*) AS n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->map(fn (mixed $n): int => (int) $n);

        $drafts = $byStatus['draft'] ?? 0;

        return Stat::make('Live products', (string) ($byStatus['active'] ?? 0))
            ->description("{$drafts} in draft · {$byStatus->sum()} in the catalogue")
            ->descriptionIcon('heroicon-m-cube')
            ->color('gray')
            ->url(ProductResource::getUrl('index'));
    }
}
