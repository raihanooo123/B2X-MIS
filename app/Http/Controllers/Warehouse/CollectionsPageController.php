<?php

namespace App\Http\Controllers\Warehouse;

use App\Domain\Collection\CashAtCollection;
use App\Domain\Collection\CashRefused;
use App\Domain\Collection\CollectionSlots;
use App\Domain\Ordering\PaymentMethod;
use App\Domain\Warehouse\DispatchDetails;
use App\Domain\Warehouse\DispatchService;
use App\Domain\Warehouse\Exceptions\FulfilmentRejectedException;
use App\Domain\Warehouse\FulfilmentRules;
use App\Filament\Support\MoneyFormatter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Collections\HandoverRequest;
use App\Http\Requests\Web\Collections\RecordCashRequest;
use App\Http\Requests\Web\Collections\VoidCashRequest;
use App\Models\CollectionBooking;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\StockAllocation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 05.6 §7A.6 — the Collections counter, `Warehouse/Collections`. Today's
 * and overdue bookings, search by order number, name, email or phone, and
 * the chosen order: its lines and picking, the amount to take in cash, the
 * receipt or invoice once taken, and the handover.
 *
 * The order of the counter is the order of the spec: identify the
 * collector, record the cash (pay-at-collection orders only), the receipt
 * or invoice is issued at once, then hand over — refused while unpaid.
 */
class CollectionsPageController extends Controller
{
    private const LIST_LIMIT = 100;

    public function __construct(
        private readonly CashAtCollection $cash = new CashAtCollection,
        private readonly DispatchService $dispatch = new DispatchService,
    ) {}

    public function show(Request $request): Response
    {
        Gate::authorize('viewAny', CollectionBooking::class);
        $search = trim((string) $request->query('q', ''));
        $selected = $request->query('order');
        $order = is_string($selected) ? Order::query()->where('public_id', $selected)->where('fulfilment_type', 'collection')->first() : null;
        $user = $request->user();

        return Inertia::render('Warehouse/Collections', [
            'search' => $search,
            'bookings' => $this->bookings($search),
            'order' => $order === null ? null : $this->detail($order),
            'can' => [
                'serve' => $user instanceof User && Gate::allows('serve', CollectionBooking::class),
                'void_cash' => $user instanceof User && Gate::allows('voidCash', CollectionBooking::class),
            ],
        ]);
    }

    public function recordCash(RecordCashRequest $request, string $order): RedirectResponse
    {
        Gate::authorize('serve', CollectionBooking::class);
        $model = Order::query()->where('public_id', $order)->firstOrFail();

        try {
            $recorded = $this->cash->record($model->id, (int) $request->user()?->id, $request->amountMinor());
        } catch (CashRefused $e) {
            return back()->withErrors(['cash' => $e->getMessage()]);
        }

        $amount = (string) MoneyFormatter::minor($recorded->payment->amount_minor);
        $document = $recorded->document;

        return back()->with('status', match (true) {
            $recorded->replayed => "{$amount} was already recorded in cash for this order.",
            $document === null => "{$amount} received in cash. The receipt could not be issued yet; accounts will issue it.",
            default => "{$amount} received in cash. {$this->documentLabel($document)} {$document->invoice_number} issued and emailed.",
        });
    }

    public function voidCash(VoidCashRequest $request, string $payment): RedirectResponse
    {
        Gate::authorize('voidCash', CollectionBooking::class);
        $model = Payment::query()->where('public_id', $payment)->firstOrFail();

        try {
            $this->cash->void($model->id, (int) $request->user()?->id, $request->reason());
        } catch (CashRefused $e) {
            return back()->withErrors(['void' => $e->getMessage()]);
        }

        return back()->with('status', 'The cash payment was voided. Record the correct amount when it is received.');
    }

    public function handover(HandoverRequest $request, string $order): RedirectResponse
    {
        Gate::authorize('serve', CollectionBooking::class);
        $model = Order::query()->where('public_id', $order)->where('fulfilment_type', 'collection')->firstOrFail();
        $shipment = Shipment::query()->where('order_id', $model->id)->whereIn('status', ['picked', 'packed'])->orderBy('id')->first();
        if ($shipment === null) {
            return back()->withErrors(['handover' => 'Pick this order before handing it over.']);
        }

        try {
            $this->dispatch->dispatch($shipment, new DispatchDetails(
                note: 'Collected at the counter',
                actorUserId: $request->user()?->id,
                collectorName: $request->collectorName(),
            ));
        } catch (FulfilmentRejectedException $e) {
            return back()->withErrors(['handover' => $e->getMessage()]);
        }

        return back()->with('status', "Order {$model->order_number} handed over.");
    }

    /**
     * Live bookings due today or overdue, oldest slot first — or, with a
     * search, any booking matching it.
     *
     * @return list<array<string, mixed>>
     */
    private function bookings(string $search): array
    {
        $today = CarbonImmutable::now(CollectionSlots::ZONE)->toDateString();

        $rows = CollectionBooking::query()
            ->with(['slot', 'order.user:id,first_name,last_name,email', 'order.company:id,name'])
            ->when($search === '', fn (Builder $q) => $q
                // qualified: the join below also has a `status` column
                ->where('collection_bookings.status', 'booked')
                ->whereHas('slot', fn (Builder $s) => $s->where('slot_date', '<=', $today)))
            ->when($search !== '', fn (Builder $q) => $q->whereHas('order', fn (Builder $o) => $this->matching($o, $search)))
            ->join('collection_slots', 'collection_slots.id', '=', 'collection_bookings.collection_slot_id')
            ->orderBy('collection_slots.slot_date')->orderBy('collection_slots.start_time')->orderBy('collection_bookings.id')
            ->limit(self::LIST_LIMIT)
            ->get(['collection_bookings.*']);

        return array_values($rows->map(function (CollectionBooking $booking) use ($today): array {
            $order = $booking->order;
            $slot = $booking->slot;

            return [
                'order_id' => $order?->public_id,
                'order_number' => $order?->order_number,
                'customer' => $order === null ? null : self::customerName($order),
                'slot' => $slot === null ? null : CollectionSlots::label($slot),
                'overdue' => $slot !== null && $slot->slot_date->format('Y-m-d') < $today,
                'status' => $booking->status,
                'payment_method' => $order?->payment_method,
                'payment_status' => $order?->payment_status,
                'amount_due_minor' => $order === null ? 0 : CashAtCollection::amountDueMinor($order),
            ];
        })->all());
    }

    /**
     * Order number, email, a name (the person, the company or the billing
     * contact) or a phone number.
     *
     * @param  Builder<Order>  $query
     */
    private function matching(Builder $query, string $search): void
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
        $query->where(fn (Builder $q) => $q
            ->where('order_number', strtoupper($search))
            ->orWhereRaw('lower(guest_email) LIKE ?', [$like])
            ->orWhereHas('user', fn (Builder $u) => $u->whereRaw("(lower(email) LIKE ? OR lower(first_name || ' ' || last_name) LIKE ? OR phone LIKE ?)", [$like, $like, $like]))
            ->orWhereHas('company', fn (Builder $c) => $c->whereRaw('lower(name) LIKE ?', [$like]))
            ->orWhereHas('addresses', fn (Builder $a) => $a->whereRaw('(lower(contact_name) LIKE ? OR phone LIKE ?)', [$like, $like])));
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Order $order): array
    {
        $order->loadMissing(['user:id,first_name,last_name,email,phone', 'company:id,name']);
        $booking = CollectionBooking::query()->with(['slot.location:id,name'])->where('order_id', $order->id)->first();
        $lines = OrderLine::query()->where('order_id', $order->id)->orderBy('line_no')->get();
        $picked = StockAllocation::query()->whereIn('order_line_id', $lines->pluck('id'))
            ->whereIn('status', FulfilmentRules::ACTIVE_ALLOCATION_STATUSES)
            ->selectRaw("order_line_id, sum(base_qty) FILTER (WHERE status = 'picked') AS picked, sum(base_qty) AS allocated")
            ->groupBy('order_line_id')->get()->keyBy('order_line_id');
        $shipment = Shipment::query()->where('order_id', $order->id)->whereNotIn('status', ['cancelled'])->orderByDesc('id')->first();
        $cash = Payment::query()->where('order_id', $order->id)->where('gateway', 'cash')->where('type', 'payment')
            ->orderByDesc('id')->get();
        $document = Invoice::query()->where('order_id', $order->id)->whereNull('shipment_id')->where('status', '<>', 'void')->first();
        $recorders = User::query()->whereIn('id', $cash->pluck('recorded_by_user_id')->filter())->get(['id', 'first_name', 'last_name'])->keyBy('id');
        $cashOrder = $order->payment_method === PaymentMethod::CashAtCollection->value;

        return [
            'id' => $order->public_id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'customer' => self::customerName($order),
            'email' => $order->user->email ?? $order->guest_email,
            'phone' => $order->user?->getAttribute('phone'),
            'is_guest' => $order->user_id === null && $order->company_id === null,
            'is_trade' => $order->company_id !== null,
            'payment_method' => $order->payment_method,
            'payment_method_label' => $order->payment_method === null ? null : PaymentMethod::tryFrom($order->payment_method)?->label(),
            'payment_status' => $order->payment_status,
            'pays_cash' => $cashOrder,
            'amount_due_minor' => CashAtCollection::amountDueMinor($order),
            'booking' => $booking === null ? null : [
                'status' => $booking->status,
                'slot' => $booking->slot === null ? null : CollectionSlots::label($booking->slot),
                'location' => $booking->slot?->location?->name,
                'payment_due_by' => $booking->payment_due_by?->toIso8601String(),
                'collector_name' => $booking->collector_name,
                'collected_at' => $booking->collected_at?->toIso8601String(),
            ],
            'lines' => array_values($lines->map(fn (OrderLine $line) => [
                'line_no' => $line->line_no,
                'name' => $line->name_snapshot,
                'sku_code' => $line->sku_code_snapshot,
                'pack_label' => $line->pack_label_snapshot,
                'pack_qty' => $line->pack_qty,
                'base_qty' => $line->base_qty,
                'cancelled_base_qty' => $line->cancelled_base_qty,
                'dispatched_base_qty' => $line->dispatched_base_qty,
                'picked_base_qty' => (int) ($picked->get($line->id)?->getAttribute('picked') ?? 0),
            ])->all()),
            'shipment' => $shipment === null ? null : [
                'id' => $shipment->public_id,
                'status' => $shipment->status,
                'pick_url' => '/warehouse/pick-list?shipment='.$shipment->public_id,
            ],
            'cash_payments' => array_values($cash->map(fn (Payment $p) => [
                'id' => $p->public_id,
                'amount_minor' => $p->amount_minor,
                'status' => $p->status,
                'captured_at' => $p->getAttribute('captured_at')?->toIso8601String(),
                'recorded_by' => $p->recorded_by_user_id === null || ($u = $recorders->get($p->recorded_by_user_id)) === null ? null : trim("{$u->first_name} {$u->last_name}"),
            ])->all()),
            'document' => $document === null ? null : [
                'label' => $this->documentLabel($document),
                'number' => $document->invoice_number,
                'status' => $document->status,
            ],
            'urls' => [
                'record_cash' => route('warehouse.collections.cash', ['order' => $order->public_id]),
                'handover' => route('warehouse.collections.handover', ['order' => $order->public_id]),
            ],
        ];
    }

    private static function customerName(Order $order): string
    {
        if ($order->company !== null) {
            return $order->company->name;
        }
        if ($order->user !== null) {
            return trim("{$order->user->first_name} {$order->user->last_name}");
        }

        return (string) $order->guest_email;
    }

    private function documentLabel(Invoice $invoice): string
    {
        return $invoice->company_id === null ? 'Receipt' : 'Invoice';
    }
}
