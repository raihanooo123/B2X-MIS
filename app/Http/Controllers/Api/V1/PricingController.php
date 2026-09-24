<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Pricing\BulkPriceResolver;
use App\Domain\Pricing\Exceptions\NoBasePriceListException;
use App\Domain\Pricing\Exceptions\NoTaxRateException;
use App\Domain\Pricing\Exceptions\NotPurchasableException;
use App\Domain\Pricing\Exceptions\PriceUnavailableForCurrencyException;
use App\Domain\Pricing\PriceBreak;
use App\Domain\Pricing\ResolvedPrice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BulkResolveRequest;
use App\Models\Sku;
use Illuminate\Http\JsonResponse;

/**
 * Doc 06 §9.1. Customer-facing: no cost, no margin, ever (CLAUDE.md
 * invariant 9) — `resolved.unit_cost_e4`/`sku_cost_id` are deliberately
 * never read from ResolvedPrice below, even though BulkPriceResolver
 * already nulls them for this path (its own docblock explains why). Two
 * independent reasons never to include them is the point: this
 * serialiser has no cost field to omit, matching 06 §10's own principle
 * for the customer-facing surface generally.
 */
class PricingController extends Controller
{
    public function __construct(
        private readonly BulkPriceResolver $bulkPriceResolver = new BulkPriceResolver,
    ) {}

    public function bulkResolve(BulkResolveRequest $request): JsonResponse
    {
        $publicIds = $request->skuPublicIds();

        // 06 §2: ULID public_ids in, never skus.id — resolved to the
        // internal id BulkPriceResolver actually works with, and mapped
        // straight back on the way out.
        $internalIdByPublicId = Sku::query()
            ->whereIn('public_id', $publicIds)
            ->pluck('id', 'public_id');

        $internalIds = array_values(array_unique(array_map('intval', $internalIdByPublicId->all())));

        $companyId = $request->user()?->companies()->value('companies.id');

        $resolution = $this->bulkPriceResolver->resolveMany(
            $internalIds,
            $companyId,
            $request->baseQty(),
            countryCode: 'GB', // no delivery address at this point in the flow — see class docblock note in the PR/report
        );

        $includeBreaks = $request->includeBreaks();

        $data = [];
        foreach ($publicIds as $publicId) {
            $internalId = $internalIdByPublicId->get($publicId);

            if ($internalId === null) {
                $data[] = $this->errorEntry($publicId, 'not_found', "No SKU found for id {$publicId}.");

                continue;
            }

            $internalId = (int) $internalId;

            if (isset($resolution->failures[$internalId])) {
                $data[] = $this->errorEntry($publicId, ...$this->failureCodeAndMessage($resolution->failures[$internalId]));

                continue;
            }

            $data[] = $this->successEntry(
                $publicId,
                $resolution->resolved[$internalId],
                $includeBreaks ? ($resolution->breaks[$internalId] ?? []) : null,
            );
        }

        return response()->json(['data' => $data]);
    }

    /**
     * @param  list<PriceBreak>|null  $breaks  null when include_breaks=false
     * @return array<string, mixed>
     */
    private function successEntry(string $publicId, ResolvedPrice $resolved, ?array $breaks): array
    {
        $entry = [
            'sku_id' => $publicId,
            'resolved' => [
                'unit_price_net_e4' => $resolved->unitPriceE4,
                'price_source' => $resolved->priceSource->value,
                'applied_break_qty' => $resolved->appliedBreakQty,
                'tax_rate_bp' => $resolved->taxRateBp,
                'next_break_qty' => $resolved->nextBreakQty,
                'next_break_unit_price_net_e4' => $resolved->nextBreakUnitPriceE4,
            ],
        ];

        if ($breaks !== null) {
            $entry['breaks'] = array_map(
                fn (PriceBreak $break): array => [
                    'min_base_qty' => $break->minBaseQty,
                    'unit_price_net_e4' => $break->unitPriceE4,
                    'price_source' => $break->priceSource->value,
                ],
                $breaks,
            );
        }

        return $entry;
    }

    /**
     * @return array<string, mixed>
     */
    private function errorEntry(string $publicId, string $code, string $message): array
    {
        return [
            'sku_id' => $publicId,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    /**
     * @param  class-string<\Throwable>  $exceptionClass
     * @return array{0: string, 1: string}
     */
    private function failureCodeAndMessage(string $exceptionClass): array
    {
        return match ($exceptionClass) {
            NotPurchasableException::class => ['not_purchasable', 'This SKU is not currently purchasable.'],
            NoBasePriceListException::class => ['no_base_price', 'No base price is available for this SKU.'],
            PriceUnavailableForCurrencyException::class => ['price_unavailable_for_currency', 'No price is available for this SKU in the requested currency.'],
            NoTaxRateException::class => ['no_tax_rate', 'No tax rate is configured for this SKU.'],
            default => ['price_unavailable', 'This SKU could not be priced.'],
        };
    }
}
