<?php

namespace App\Domain\Ordering;

use App\Domain\Billing\CardPayments;
use App\Domain\Delivery\ConsignmentWeigher;
use App\Domain\Delivery\DeliveryDestination;
use App\Domain\Delivery\DeliveryQuote;
use App\Domain\Delivery\DeliveryQuoter;
use App\Domain\Delivery\Exceptions\CarriageQuoteRequiredException;
use App\Domain\Inventory\AllocationLine;
use App\Domain\Inventory\AllocationService;
use App\Domain\Inventory\DeadlockRetryPolicy;
use App\Domain\Inventory\Exceptions\InsufficientCreditException;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Ordering\Events\OrderPlaced;
use App\Domain\Ordering\Exceptions\BatchTrackedCheckoutNotSupportedException;
use App\Domain\Ordering\Exceptions\PriceChangedException;
use App\Domain\Pricing\OrderLineRequest;
use App\Domain\Pricing\OrderPricingPipeline;
use App\Domain\Pricing\OrderPricingResult;
use App\Domain\Reference\NumberSequenceService;
use App\Models\Cart;
use App\Models\CartLine;
use App\Models\Company;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Doc 02 §11.1 / 05.2 §8.2 — the single global lock order, as far as it
 * can be followed with what exists today: `companies` (credit) then
 * `stock_levels` (ascending sku_id, location_id, batch_id NULLS FIRST).
 * `collection_slots` is the middle step of the documented three-step
 * order, but that table does not exist yet (Phase 2), so this service
 * covers the delivery/dropship fulfilment paths only, exactly as
 * AllocationService's own docblock already states — not a new gap,
 * the same one, now visible at the checkout layer too.
 *
 * The company_id-dependent half of checkout — whether a credit gate
 * applies, which price tier, what payment_status starts at — is NOT
 * decided here. It's delegated to a CheckoutStrategy (TradeCheckout or
 * ConsumerCheckout, chosen by strategyFor() below), so this class stays
 * the company-agnostic transaction skeleton: load cart, price, compare,
 * create order + order_lines, ask the strategy to reserve stock (and
 * credit, if it has any), take the order number last, clear the cart,
 * dispatch the event.
 *
 * Sequence, one retryable unit (04 §4.5 — "the whole transaction is
 * retried, never resumed"):
 *
 *   0. (before any lock) Resolve prices via OrderPricingPipeline and
 *      compare against expectedTotalGrossMinor. A mismatch throws
 *      PriceChangedException and opens no transaction at all — the same
 *      reasoning 05.2 §8.2 gives for checking credit before locking
 *      stock: a doomed request should take no lock of any kind.
 *   1. INSERT orders (placeholder order_number — see below) and
 *      order_lines, with full price/cost/pack snapshots (CLAUDE.md
 *      invariants 2 and 4). Inserting new rows this transaction owns
 *      does not conflict with the lock-ordering rule; that rule is
 *      about which EXISTING rows get FOR UPDATE and in what order.
 *   2. CheckoutStrategy::reserve() — for TradeCheckout, locks companies
 *      (only when on_account) then stock_levels ascending, verifies
 *      availability, writes stock_allocations/movements, then inserts
 *      credit_holds / updates credit_held_minor (02 §11.1 step 6,
 *      after stock, not alongside the step-1 lock). For ConsumerCheckout,
 *      only the stock half runs. Runs inside THIS transaction, not a
 *      nested one (see AllocationService's docblock for why that split
 *      exists) — a deadlock retries the whole checkout, order insert
 *      included, never just the allocation.
 *   3. NumberSequenceService::next('order_number') — taken LAST, per 02
 *      §11.3, then written over the placeholder. A rolled-back checkout
 *      (any exception above) consumes no number.
 *   4. Side effects — none synchronous. OrderPlaced is dispatched via
 *      DB::afterCommit() only (CLAUDE.md invariant 6 / 04 §4.4).
 *
 * Deliberately NOT built here (see the class's own exceptions and the
 * session report for the full list): batch/serial-tracked SKU checkout,
 * the awaiting_approval fallback for an on-account order that exceeds
 * credit (05.2 §8.1 row 3 — this throws InsufficientCreditException and
 * commits nothing instead), delivery-rate/collection-slot resolution,
 * and actual payment capture (04 §4.4's "authorised before, captured
 * after" — payment_method is trusted as already authorised upstream).
 */
final class CheckoutService
{
    public function __construct(
        private readonly OrderPricingPipeline $pricingPipeline = new OrderPricingPipeline,
        private readonly AllocationService $allocationService = new AllocationService,
        private readonly NumberSequenceService $numberSequenceService = new NumberSequenceService,
        private readonly CheckoutStrategy $tradeCheckout = new TradeCheckout,
        private readonly CheckoutStrategy $consumerCheckout = new ConsumerCheckout,
        private readonly DeliveryQuoter $deliveryQuoter = new DeliveryQuoter,
    ) {}

    /**
     * @throws PriceChangedException
     * @throws CarriageQuoteRequiredException
     * @throws BatchTrackedCheckoutNotSupportedException
     * @throws InsufficientCreditException
     * @throws InsufficientStockException
     */
    public function checkout(CheckoutRequest $request): Order
    {
        if (PaymentMethod::tryFrom($request->paymentMethod) === null) {
            throw new InvalidArgumentException("Unknown payment_method '{$request->paymentMethod}'.");
        }

        $strategy = $this->strategyFor($request);
        $strategy->validate($request);

        // Lines in id order — the same order CheckoutPreviewService prices
        // them in. Spend-break apportionment distributes its rounding
        // remainder by line position, which moves per-line tax, so an
        // unordered load could make preview and checkout disagree by a
        // penny (06 §16 criterion 3).
        $cart = Cart::query()
            ->with(['lines' => fn ($q) => $q->orderBy('id'), 'lines.sku.product', 'lines.pack'])
            ->findOrFail($request->cartId);

        if ($cart->lines->isEmpty()) {
            throw new InvalidArgumentException("Cart {$request->cartId} has no lines — nothing to check out.");
        }

        $lineRequests = array_values(
            $cart->lines
                ->map(fn (CartLine $line) => new OrderLineRequest(skuId: $line->sku_id, baseQty: $line->base_qty))
                ->all()
        );

        $pricing = $this->pricingPipeline->price(
            $lineRequests,
            companyId: $request->companyId,
            tierId: $strategy->tierId($request),
            deliveryCountryCode: $request->deliveryCountryCode,
            currency: 'GBP',
            shippingNetMinor: $request->shippingNetMinor,
        );

        // Carriage (05.6): rated on the post-spend-break subtotal, exactly as
        // checkout preview rated it, before any lock. Carriage that cannot be
        // rated stops the order here — nothing placed, nothing charged,
        // never £0 by default.
        $delivery = null;
        if ($request->deliveryAddress !== null) {
            $delivery = $this->deliveryQuoter->quote(
                ConsignmentWeigher::linesFromCart($cart->lines),
                new DeliveryDestination($request->deliveryAddress->postcode, $request->deliveryAddress->countryCode),
                $request->companyId,
                $pricing->subtotalNetMinor,
            );

            if (! $delivery->isChargeable()) {
                throw new CarriageQuoteRequiredException($delivery);
            }

            $pricing = $pricing->withShipping($delivery->shippingNetMinor, $delivery->taxRateBp);
        }

        // Step 0 — before any lock is taken. See class docblock.
        if ($pricing->totalGrossMinor !== $request->expectedTotalGrossMinor) {
            throw new PriceChangedException($request->expectedTotalGrossMinor, $pricing->totalGrossMinor);
        }

        // A card authorisation must cover exactly what is being charged.
        $card = $request->cardAuthorisation;
        if ($card !== null && ($card->amountMinor !== $pricing->totalGrossMinor || ! $card->isAuthorised())) {
            throw new PriceChangedException($card->amountMinor, $pricing->totalGrossMinor);
        }

        $defaultLocation = Location::query()->where('is_default', true)->firstOrFail();

        return (new DeadlockRetryPolicy)->run(
            fn () => DB::transaction(function () use ($request, $strategy, $cart, $pricing, $defaultLocation, $delivery) {
                $order = $this->createDraftOrder($request, $pricing, $strategy->paymentStatus($request), $delivery);

                if ($request->deliveryAddress !== null) {
                    OrderAddress::create(['order_id' => $order->id, 'address_type' => 'delivery'] + $request->deliveryAddress->toSnapshot());
                }
                $allocationLines = $this->createOrderLines($order, $cart, $pricing, $defaultLocation);

                $strategy->reserve($this->allocationService, $request, $order, $pricing->totalGrossMinor, $allocationLines);

                // 07 §6.4: the authorised card payment exists with its order
                // or not at all. No gateway call here (04 §4.4) — capture
                // is the caller's, after commit.
                if ($request->cardAuthorisation !== null) {
                    CardPayments::recordAuthorisation($order->id, $request->companyId, $request->cardAuthorisation);
                }

                // Step 3 — taken last, per 02 §11.3.
                $orderNumber = $this->numberSequenceService->next('order_number');
                $now = now();
                $order->update([
                    'order_number' => $orderNumber,
                    'status' => 'confirmed',
                    'placed_at' => $now,
                    'confirmed_at' => $now,
                ]);

                $cart->lines()->delete();

                DB::afterCommit(fn () => event(new OrderPlaced($order->id)));

                return $order->load('lines');
            }),
            self::class,
        );
    }

    private function strategyFor(CheckoutRequest $request): CheckoutStrategy
    {
        return $request->companyId !== null ? $this->tradeCheckout : $this->consumerCheckout;
    }

    private function createDraftOrder(CheckoutRequest $request, OrderPricingResult $pricing, string $paymentStatus, ?DeliveryQuote $delivery): Order
    {
        return Order::create([
            // Placeholder, unique and syntactically valid — overwritten
            // with the real gapless number at step 3. Never visible
            // outside this transaction (READ COMMITTED, uncommitted rows
            // are invisible to every other transaction).
            'order_number' => (string) Str::ulid(),
            'company_id' => $request->companyId,
            'user_id' => $request->userId,
            'placed_by_user_id' => $request->placedByUserId ?? $request->userId,
            'channel' => $request->channel,
            'status' => 'draft',
            'payment_status' => $paymentStatus,
            // 02 §18: what the buyer chose, so the order says how it is being paid.
            'payment_method' => $request->paymentMethod,
            'fulfilment_type' => 'delivery',
            'currency' => 'GBP',
            'subtotal_net_minor' => $pricing->subtotalNetMinor,
            'shipping_net_minor' => $pricing->shippingNetMinor,
            // 02 §20.2: the carriage snapshot, never re-resolved (05.6 §8).
            'shipping_tax_minor' => $pricing->shippingTaxMinor,
            'shipping_tax_rate_bp' => $pricing->shippingTaxRateBp,
            'delivery_zone_id' => $delivery?->zone?->id,
            'delivery_rate_id' => $delivery?->rateId,
            'delivery_method' => $delivery?->method,
            'tax_minor' => $pricing->taxMinor,
            'total_gross_minor' => $pricing->totalGrossMinor,
            'spend_break_id' => $pricing->spendBreakId,
            'spend_break_discount_minor' => $pricing->spendBreakDiscountMinor,
            'customer_reference' => $request->customerReference,
        ]);
    }

    /**
     * Inserts one order_line per cart line, snapshotting price, cost and
     * pack data immutably (CLAUDE.md invariants 2 and 4), and returns the
     * AllocationLine list for every stock-tracked, untracked-mode line —
     * see the class docblock for why batch-tracked lines are rejected
     * rather than silently skipped.
     *
     * @return list<AllocationLine>
     */
    private function createOrderLines(Order $order, Cart $cart, OrderPricingResult $pricing, Location $defaultLocation): array
    {
        $allocationLines = [];

        foreach ($cart->lines as $index => $cartLine) {
            $pricedLine = $pricing->lines[$index];

            // Eager-loaded by checkout() ('lines.sku.product', 'lines.pack')
            // and guaranteed by the cart_lines/skus/packs FKs (all NOT
            // NULL) — the null-coalesce is a defensive assertion for
            // PHPStan's benefit, not a code path expected to fire.
            $sku = $cartLine->sku ?? throw new RuntimeException("CartLine {$cartLine->id} has no sku loaded.");
            $pack = $cartLine->pack ?? throw new RuntimeException("CartLine {$cartLine->id} has no pack loaded.");
            $product = $sku->product ?? throw new RuntimeException("Sku {$sku->id} has no product loaded.");

            $orderLine = OrderLine::create([
                'order_id' => $order->id,
                'line_no' => $index + 1,
                'sku_id' => $sku->id,
                'pack_id' => $pack->id,
                'sku_code_snapshot' => $sku->sku_code,
                'name_snapshot' => $product->name,
                'pack_label_snapshot' => $pack->label,
                'pack_qty' => $cartLine->pack_qty,
                'pack_base_units' => $cartLine->pack_base_units,
                'base_qty' => $cartLine->base_qty,
                'unit_price_net_e4' => $pricedLine->unitPriceNetE4,
                'line_discount_minor' => $pricedLine->lineDiscountMinor,
                'line_spend_discount_minor' => $pricedLine->lineSpendDiscountMinor,
                'line_net_minor' => $pricedLine->lineNetMinor,
                'tax_rate_bp' => $pricedLine->taxRateBp,
                'line_tax_minor' => $pricedLine->lineTaxMinor,
                'line_gross_minor' => $pricedLine->lineGrossMinor,
                'price_source' => $pricedLine->priceSource->value,
                'price_list_id' => $pricedLine->priceListId,
                'price_list_item_id' => $pricedLine->priceListItemId,
                'applied_break_qty' => $pricedLine->appliedBreakQty,
                'unit_cost_e4' => $pricedLine->unitCostE4,
                'sku_cost_id' => $pricedLine->skuCostId,
            ]);

            if (! $sku->is_stock_tracked) {
                continue;
            }

            if ($sku->tracking_mode !== 'none') {
                throw new BatchTrackedCheckoutNotSupportedException($sku->id);
            }

            $allocationLines[] = new AllocationLine($orderLine->id, $sku->id, $defaultLocation->id, null, $cartLine->base_qty);
        }

        return $allocationLines;
    }
}
