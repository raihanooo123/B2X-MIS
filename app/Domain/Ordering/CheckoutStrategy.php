<?php

namespace App\Domain\Ordering;

use App\Domain\Inventory\AllocationLine;
use App\Domain\Inventory\AllocationService;
use App\Domain\Inventory\Exceptions\InsufficientCreditException;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Models\Order;
use InvalidArgumentException;

/**
 * The company_id-dependent half of checkout, extracted out of
 * CheckoutService so the trade (on-account, credit-checked) and
 * consumer/public (card/prepay, no credit) paths can diverge without
 * tangling into one branchy method — done now, per the request that
 * introduced this split, "while there's only one branch," before more
 * accumulate (05.2's awaiting_approval fallback, suspension checks,
 * multi-currency, etc. — all still unbuilt, all trade-only concerns).
 *
 * CheckoutService owns everything company-agnostic: loading the cart,
 * resolving and comparing prices, creating the order/order_lines with
 * their snapshots, taking the order number last, clearing the cart, and
 * dispatching the post-commit event. A CheckoutStrategy owns only what
 * genuinely differs: whether the request is well-formed at all, which
 * price tier applies, what `orders.payment_status` starts at, and how
 * stock (and, for trade, credit) get reserved.
 */
interface CheckoutStrategy
{
    /**
     * @throws InvalidArgumentException if the request is not valid for this strategy
     */
    public function validate(CheckoutRequest $request): void;

    /**
     * The price_tier_id OrderPricingPipeline resolves against, or null.
     */
    public function tierId(CheckoutRequest $request): ?int;

    /**
     * `orders.payment_status` for the freshly confirmed order.
     */
    public function paymentStatus(CheckoutRequest $request): string;

    /**
     * Reserves stock for $allocationLines and, for a strategy that has
     * one, runs the credit gate and places the credit hold — in the
     * 02 §11.1 / 05.2 §8.2 global lock order (companies before
     * stock_levels), inside the caller's already-open transaction.
     * MUST be called from inside an existing transaction.
     *
     * @param  list<AllocationLine>  $allocationLines
     *
     * @throws InsufficientCreditException
     * @throws InsufficientStockException
     */
    public function reserve(
        AllocationService $allocationService,
        CheckoutRequest $request,
        Order $order,
        int $totalGrossMinor,
        array $allocationLines,
    ): void;
}
