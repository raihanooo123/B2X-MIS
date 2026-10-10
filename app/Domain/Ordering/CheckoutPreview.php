<?php

namespace App\Domain\Ordering;

use App\Domain\Collection\PayAtCollectionOffer;
use App\Domain\Delivery\DeliveryQuote;
use App\Domain\Pricing\PricedOrderLine;
use App\Models\CartLine;
use App\Models\CollectionSlot;
use App\Models\OrderSpendBreak;

/**
 * CheckoutPreviewService's result. `pricedLines` is keyed by the cart
 * line's internal id and holds only the lines that could be priced — a
 * line blocked as not purchasable has no entry, and the totals exclude
 * it (checkout is blocked anyway while it is in the cart).
 *
 * PricedOrderLine carries `unitCostE4`/`skuCostId` from the pipeline;
 * nothing that serialises this object may read them (CLAUDE.md
 * invariant 9). CheckoutPreviewResource has no field for them.
 */
final readonly class CheckoutPreview
{
    /**
     * @param  list<CartLine>  $cartLines
     * @param  array<int, PricedOrderLine>  $pricedLines
     * @param  list<CheckoutBlocker>  $blockers
     */
    public function __construct(
        public array $cartLines,
        public array $pricedLines,
        public int $subtotalNetMinor,
        public ?OrderSpendBreak $spendBreak,
        public int $spendBreakDiscountMinor,
        public int $shippingNetMinor,
        public int $taxMinor,
        public int $totalGrossMinor,
        public int $accountCreditAppliedMinor,
        public ?int $creditAvailableMinor,
        public ?int $minimumOrderNetMinor,
        public array $blockers,
        /** 05.6: null when no destination was given; otherwise the carriage, or why there is none. */
        public ?DeliveryQuote $delivery = null,
        public int $shippingTaxMinor = 0,
        /** 02 §27: a consumer's pallet consignment — estimated return cost, null when we collect at our cost. */
        public bool $palletReturn = false,
        public ?int $returnCostEstimateGrossMinor = null,
        /** 05.6 §7A: the collection slot chosen, when it is still available. */
        public ?CollectionSlot $collectionSlot = null,
        public ?PayAtCollectionOffer $payAtCollection = null,
        /**
         * 05.2 §18.1: why this trade order would wait for a decision —
         * `buyer_limit` (another owner/approver) and/or `credit_shortfall`
         * (accounts; nothing reserved meanwhile). Empty: placed straight away.
         *
         * @var list<'buyer_limit'|'credit_shortfall'>
         */
        public array $approvalReasons = [],
    ) {}

    public function amountDueMinor(): int
    {
        return $this->totalGrossMinor - $this->accountCreditAppliedMinor;
    }
}
