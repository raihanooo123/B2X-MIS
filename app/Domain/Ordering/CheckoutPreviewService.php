<?php

namespace App\Domain\Ordering;

use App\Domain\Pricing\BulkPriceResolver;
use App\Domain\Pricing\Exceptions\NoBasePriceListException;
use App\Domain\Pricing\Exceptions\NoTaxRateException;
use App\Domain\Pricing\Exceptions\NotPurchasableException;
use App\Domain\Pricing\Exceptions\PriceUnavailableForCurrencyException;
use App\Domain\Pricing\OrderLineRequest;
use App\Domain\Pricing\OrderPricingPipeline;
use App\Models\B2bApplication;
use App\Models\Cart;
use App\Models\CartLine;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Location;
use App\Models\OrderSpendBreak;
use App\Models\StockLevel;
use App\Models\SystemConfiguration;
use App\Models\User;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Doc 06 §9.2 — checkout preview. **Side-effect-free**: no allocation,
 * no credit hold, no slot booking, no write of any kind. Every query in
 * this class is a plain SELECT with no `FOR UPDATE`; it may be called on
 * every cart change.
 *
 * Totals come from the same OrderPricingPipeline call CheckoutService
 * makes — same line requests (cart line sku_id + base_qty, no line
 * discount), same tier (the company's `price_tier_id`, null for a
 * consumer), same currency, same shipping (0: delivery-rate resolution
 * is not built in checkout either) — so 06 §16 criterion 3 ("preview
 * totals match checkout exactly") holds by construction, not by a
 * parallel implementation.
 *
 * Blockers predict what checkout would refuse, and so mirror what
 * checkout *actually* enforces today, not only what 05.1 §6 describes:
 *
 *   - `insufficient_stock` is checked at the default location with
 *     `batch_id IS NULL`, because that is the only stock_levels identity
 *     CheckoutService allocates from. It ignores `allow_backorder`,
 *     because AllocationService does too — 05.1 §6's "warned, not
 *     blocked" for backorderable SKUs is not built in checkout yet.
 *   - `batch_tracked_not_supported` mirrors
 *     BatchTrackedCheckoutNotSupportedException.
 *   - `fulfilment_type_unsupported` — checkout only does delivery.
 *
 * MOQ, increment and max-order come from 05.1 §6 and are deliberately
 * not enforced by CartService (see its docblock); this is where they
 * become blocking.
 *
 * Who may place the order is checked here too, when the caller passes
 * the user (05.13): a guest must sign in (§4.1), an applicant waits for
 * approval (`application_pending`, §7), a public customer must confirm
 * their email (`email_unverified`, §11), and a company `viewer` cannot
 * order (05.2 §10).
 */
final class CheckoutPreviewService
{
    public const MINIMUM_ORDER_CONFIG_KEY = 'orders.minimum_value_net_minor';

    public function __construct(
        private readonly OrderPricingPipeline $pricingPipeline = new OrderPricingPipeline,
        private readonly BulkPriceResolver $bulkPriceResolver = new BulkPriceResolver,
    ) {}

    public function preview(
        ?Cart $cart,
        ?int $companyId,
        string $deliveryCountryCode,
        string $fulfilmentType = 'delivery',
        ?CarbonImmutable $at = null,
        ?User $user = null,
        bool $checkIdentity = false,
    ): CheckoutPreview {
        $at ??= CarbonImmutable::now();

        /** @var list<CartLine> $cartLines */
        $cartLines = $cart === null
            ? []
            : array_values($cart->lines()->with(['sku', 'pack'])->orderBy('id')->get()->all());

        $blockers = [];

        if ($fulfilmentType !== 'delivery') {
            $blockers[] = new CheckoutBlocker('fulfilment_type', 'fulfilment_type_unsupported', "Only delivery checkout is available; '{$fulfilmentType}' is not supported yet.", ['fulfilment_type' => $fulfilmentType]);
        }

        // Listed after the cart's own blockers, whatever the path out.
        $identityBlockers = $checkIdentity ? $this->identityBlockers($user, $companyId) : [];

        if ($cartLines === []) {
            $blockers[] = new CheckoutBlocker(null, 'cart_empty', 'The cart is empty.');

            return $this->emptyPreview($companyId, [...$blockers, ...$identityBlockers]);
        }

        $priceable = [];
        foreach ($cartLines as $index => $line) {
            $lineBlockers = $this->lineRuleBlockers($index, $line);
            array_push($blockers, ...$lineBlockers);

            if (! $this->hasBlocker($lineBlockers, 'not_purchasable')) {
                $priceable[$index] = $line;
            }
        }

        array_push($blockers, ...$this->stockBlockers($cartLines));

        // Price failures (no base price, no tax rate, ...) are per SKU and
        // quantity-independent; BulkPriceResolver reports them without
        // throwing, so one unpriceable line blocks itself instead of
        // failing the whole preview. The pipeline then prices the rest.
        $priceFailures = $this->bulkPriceResolver->resolveMany(
            array_values(array_unique(array_map(fn (CartLine $l) => $l->sku_id, $priceable))),
            $companyId,
            1,
            $deliveryCountryCode,
            $at,
        )->failures;

        foreach ($priceable as $index => $line) {
            if (isset($priceFailures[$line->sku_id])) {
                $blockers[] = $this->priceFailureBlocker($index, $line, $priceFailures[$line->sku_id]);
                unset($priceable[$index]);
            }
        }

        if ($priceable === []) {
            return $this->emptyPreview($companyId, [...$blockers, ...$identityBlockers], $cartLines);
        }

        $pricing = $this->pricingPipeline->price(
            array_values(array_map(fn (CartLine $l) => new OrderLineRequest(skuId: $l->sku_id, baseQty: $l->base_qty), $priceable)),
            companyId: $companyId,
            tierId: $this->tierId($companyId),
            deliveryCountryCode: $deliveryCountryCode,
            currency: 'GBP',
            at: $at,
            shippingNetMinor: 0,
        );

        $pricedLines = [];
        foreach (array_values($priceable) as $i => $line) {
            $pricedLines[$line->id] = $pricing->lines[$i];
        }

        $minimumNetMinor = $this->minimumOrderNetMinor($companyId);
        if ($minimumNetMinor !== null && $pricing->subtotalNetMinor < $minimumNetMinor) {
            $blockers[] = new CheckoutBlocker(null, 'below_minimum_order', 'The order is below the minimum order value.', [
                'minimum_net_minor' => $minimumNetMinor,
                'subtotal_net_minor' => $pricing->subtotalNetMinor,
                'shortfall_minor' => $minimumNetMinor - $pricing->subtotalNetMinor,
            ]);
        }

        return new CheckoutPreview(
            cartLines: $cartLines,
            pricedLines: $pricedLines,
            subtotalNetMinor: $pricing->subtotalNetMinor,
            spendBreak: $pricing->spendBreakId !== null ? OrderSpendBreak::query()->find($pricing->spendBreakId) : null,
            spendBreakDiscountMinor: $pricing->spendBreakDiscountMinor,
            shippingNetMinor: $pricing->shippingNetMinor,
            taxMinor: $pricing->taxMinor,
            totalGrossMinor: $pricing->totalGrossMinor,
            // 05.4 §7.5A's account-balance ledger is not built; nothing
            // can be applied until it is.
            accountCreditAppliedMinor: 0,
            creditAvailableMinor: $this->creditAvailableMinor($companyId),
            minimumOrderNetMinor: $minimumNetMinor,
            blockers: [...$blockers, ...$identityBlockers],
        );
    }

    /**
     * 05.13 §4.1, §7, §11; 05.2 §10 — whether this person may place an
     * order at all, independent of what is in the cart.
     *
     * @return list<CheckoutBlocker>
     */
    private function identityBlockers(?User $user, ?int $companyId): array
    {
        if ($user === null) {
            return [new CheckoutBlocker(null, 'sign_in_required', 'Sign in or create an account to check out.')];
        }

        if ($companyId !== null) {
            $role = CompanyUser::query()->where('company_id', $companyId)->where('user_id', $user->id)->value('role');

            return $role === 'viewer'
                ? [new CheckoutBlocker(null, 'not_permitted_to_order', 'Your account can view prices and orders but not place them. Ask your account owner.')]
                : [];
        }

        $applicationOpen = B2bApplication::query()
            ->where('applicant_user_id', $user->id)
            ->whereIn('status', ['submitted', 'in_review', 'info_requested'])
            ->exists();

        if ($applicationOpen) {
            return [new CheckoutBlocker(null, 'application_pending', 'Your trade account application is being reviewed. You can check out once it is approved.')];
        }

        return $user->hasVerifiedEmail()
            ? []
            : [new CheckoutBlocker(null, 'email_unverified', 'Confirm your email address to check out — we have sent you a link.')];
    }

    /**
     * 05.1 §6: pack sellable, SKU active, MOQ, order increment, max order;
     * plus the checkout-only batch-tracking restriction.
     *
     * @return list<CheckoutBlocker>
     */
    private function lineRuleBlockers(int $index, CartLine $line): array
    {
        $sku = $line->sku ?? throw new RuntimeException("CartLine {$line->id} has no sku loaded.");
        $pack = $line->pack ?? throw new RuntimeException("CartLine {$line->id} has no pack loaded.");
        $field = "lines.{$index}";
        $meta = ['cart_line_id' => $line->public_id, 'sku_id' => $sku->public_id];

        if ($sku->status !== 'active') {
            // 05.1 §10: "SKU deactivated while in the cart — line flagged,
            // blocked from checkout, cart otherwise intact."
            return [new CheckoutBlocker($field, 'not_purchasable', 'This item is no longer available to order.', $meta + ['status' => $sku->status])];
        }

        $blockers = [];

        if (! $pack->is_sellable) {
            $blockers[] = new CheckoutBlocker("{$field}.pack_code", 'pack_not_sellable', 'This pack size is not sold; choose another.', $meta + ['pack_code' => $pack->code]);
        }

        $moq = (int) $sku->moq_base_qty;
        if ($line->base_qty < $moq) {
            $blockers[] = new CheckoutBlocker("{$field}.base_qty", 'moq_not_met', "The minimum order for this item is {$moq} units.", $meta + [
                'requested_base_qty' => $line->base_qty,
                'moq_base_qty' => $moq,
                // Stated in pack terms (05.1 §6), rounded up to whole packs.
                'moq_pack_qty' => intdiv($moq + $line->pack_base_units - 1, $line->pack_base_units),
            ]);
        }

        $increment = (int) $sku->order_increment_base_qty;
        if ($line->base_qty % $increment !== 0) {
            $blockers[] = new CheckoutBlocker("{$field}.base_qty", 'order_increment_violation', "This item is sold in multiples of {$increment} units.", $meta + [
                'requested_base_qty' => $line->base_qty,
                'order_increment_base_qty' => $increment,
                'next_valid_base_qty' => intdiv($line->base_qty + $increment - 1, $increment) * $increment,
            ]);
        }

        $max = $sku->max_order_base_qty === null ? null : (int) $sku->max_order_base_qty;
        if ($max !== null && $line->base_qty > $max) {
            $blockers[] = new CheckoutBlocker("{$field}.base_qty", 'max_order_exceeded', "The maximum order for this item is {$max} units.", $meta + [
                'requested_base_qty' => $line->base_qty,
                'max_order_base_qty' => $max,
            ]);
        }

        if ($sku->is_stock_tracked && $sku->tracking_mode !== 'none') {
            $blockers[] = new CheckoutBlocker($field, 'batch_tracked_not_supported', 'This item cannot be checked out online yet.', $meta);
        }

        return $blockers;
    }

    /**
     * One blocker per short SKU, not per line: two lines for the same SKU
     * in different packs draw on the same stock, exactly as
     * AllocationService sums them per (sku, location, batch).
     *
     * @param  list<CartLine>  $cartLines
     * @return list<CheckoutBlocker>
     */
    private function stockBlockers(array $cartLines): array
    {
        $requiredBySku = [];
        $firstIndexBySku = [];
        $lineIdsBySku = [];
        $publicSkuIdBySku = [];

        foreach ($cartLines as $index => $line) {
            $sku = $line->sku ?? throw new RuntimeException("CartLine {$line->id} has no sku loaded.");
            if (! $sku->is_stock_tracked || $sku->tracking_mode !== 'none' || $sku->status !== 'active') {
                continue;
            }

            $requiredBySku[$sku->id] = ($requiredBySku[$sku->id] ?? 0) + $line->base_qty;
            $firstIndexBySku[$sku->id] ??= $index;
            $lineIdsBySku[$sku->id][] = (string) $line->public_id;
            $publicSkuIdBySku[$sku->id] = (string) $sku->public_id;
        }

        if ($requiredBySku === []) {
            return [];
        }

        $locationId = Location::query()->where('is_default', true)->value('id');

        $availableBySku = $locationId === null ? [] : StockLevel::query()
            ->where('location_id', $locationId)
            ->whereNull('batch_id')
            ->whereIn('sku_id', array_keys($requiredBySku))
            ->pluck('available_base_qty', 'sku_id')
            ->map(fn ($qty) => (int) $qty)
            ->all();

        $blockers = [];
        foreach ($requiredBySku as $skuId => $required) {
            $available = $availableBySku[$skuId] ?? 0;
            if ($required > $available) {
                $blockers[] = new CheckoutBlocker("lines.{$firstIndexBySku[$skuId]}.base_qty", 'insufficient_stock', "Only {$available} units available.", [
                    'sku_id' => $publicSkuIdBySku[$skuId],
                    'cart_line_ids' => $lineIdsBySku[$skuId],
                    'requested_base_qty' => $required,
                    'available_base_qty' => $available,
                ]);
            }
        }

        return $blockers;
    }

    /**
     * @param  class-string<\Throwable>  $exceptionClass
     */
    private function priceFailureBlocker(int $index, CartLine $line, string $exceptionClass): CheckoutBlocker
    {
        [$code, $message] = match ($exceptionClass) {
            NotPurchasableException::class => ['not_purchasable', 'This item is no longer available to order.'],
            NoBasePriceListException::class => ['no_base_price', 'No price is available for this item.'],
            PriceUnavailableForCurrencyException::class => ['price_unavailable_for_currency', 'No price is available for this item in this currency.'],
            NoTaxRateException::class => ['no_tax_rate', 'No tax rate is configured for this item at the delivery destination.'],
            default => ['price_unavailable', 'This item could not be priced.'],
        };

        return new CheckoutBlocker("lines.{$index}", $code, $message, [
            'cart_line_id' => $line->public_id,
            'sku_id' => $line->sku?->public_id,
        ]);
    }

    /**
     * 02 §2.7 most-specific-wins, company then global (location scope has
     * no meaning for an order-value threshold). Unconfigured means no
     * minimum — the go-live value is an open client decision (ROADMAP
     * §0.4) and is never guessed here.
     */
    private function minimumOrderNetMinor(?int $companyId): ?int
    {
        $rows = SystemConfiguration::query()
            ->where('config_key', self::MINIMUM_ORDER_CONFIG_KEY)
            ->where(function ($q) use ($companyId) {
                $q->where('scope', 'global');
                if ($companyId !== null) {
                    $q->orWhere(fn ($q) => $q->where('scope', 'company')->where('company_id', $companyId));
                }
            })
            ->get(['scope', 'value_int'])
            ->keyBy('scope');

        $row = $rows->get('company') ?? $rows->get('global');

        return $row?->value_int;
    }

    private function tierId(?int $companyId): ?int
    {
        if ($companyId === null) {
            return null;
        }

        $tierId = Company::query()->whereKey($companyId)->value('price_tier_id');

        return $tierId === null ? null : (int) $tierId;
    }

    /**
     * Same formula as AllocationService::lockCompanyCredit(), read
     * without the lock.
     */
    private function creditAvailableMinor(?int $companyId): ?int
    {
        if ($companyId === null) {
            return null;
        }

        $company = Company::query()->find($companyId, ['credit_limit_minor', 'credit_used_minor', 'credit_held_minor']);

        return $company === null ? null
            : (int) $company->credit_limit_minor - (int) $company->credit_used_minor - (int) $company->credit_held_minor;
    }

    /**
     * @param  list<CheckoutBlocker>  $blockers
     */
    private function hasBlocker(array $blockers, string $code): bool
    {
        foreach ($blockers as $blocker) {
            if ($blocker->code === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<CheckoutBlocker>  $blockers
     * @param  list<CartLine>  $cartLines
     */
    private function emptyPreview(?int $companyId, array $blockers, array $cartLines = []): CheckoutPreview
    {
        return new CheckoutPreview(
            cartLines: $cartLines,
            pricedLines: [],
            subtotalNetMinor: 0,
            spendBreak: null,
            spendBreakDiscountMinor: 0,
            shippingNetMinor: 0,
            taxMinor: 0,
            totalGrossMinor: 0,
            accountCreditAppliedMinor: 0,
            creditAvailableMinor: $this->creditAvailableMinor($companyId),
            minimumOrderNetMinor: $this->minimumOrderNetMinor($companyId),
            blockers: $blockers,
        );
    }
}
