<?php

namespace App\Domain\Seo;

use App\Domain\Inventory\StockLabels;
use App\Domain\Pricing\Money;
use App\Domain\Storefront\Branding;
use App\Filament\Support\MoneyFormatter;

/**
 * 05.11 §5 — schema.org JSON-LD for the storefront: `Product`,
 * `BreadcrumbList` and `Organization`.
 *
 * Always from a logged-out visitor's point of view, whoever is looking:
 * the caller passes product data resolved with no company (rank 5 `base`,
 * 03 §4.2) and GB VAT, so a trade viewer's own price never reaches a block
 * crawlers read. Never cost (CLAUDE.md invariant 9), never a stock figure:
 * availability is the label only. Prices are formatted from integers, as
 * the page shows them (05.15 §4.3); no float is involved.
 */
final class StructuredData
{
    private const AVAILABILITY = [
        StockLabels::IN_STOCK => 'https://schema.org/InStock',
        StockLabels::LOW_STOCK => 'https://schema.org/LimitedAvailability',
        StockLabels::BACKORDER => 'https://schema.org/BackOrder',
        StockLabels::OUT_OF_STOCK => 'https://schema.org/OutOfStock',
    ];

    /** Stock labels from best to worst, for a product's overall availability. */
    private const LABEL_ORDER = [StockLabels::IN_STOCK, StockLabels::LOW_STOCK, StockLabels::BACKORDER, StockLabels::OUT_OF_STOCK];

    /**
     * @param  array<string, mixed>  $product  ProductDetail::bySlug() resolved with no company
     * @param  string|null  $barcode  the single SKU's `barcode_ean`, if any
     * @return array<string, mixed>
     */
    public static function product(array $product, string $url, Branding $brand, ?string $barcode = null): array
    {
        /** @var list<array<string, mixed>> $variants */
        $variants = $product['variants'];
        /** @var list<array{url: string, alt: string|null}> $images */
        $images = $product['images'];

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => (string) $product['name'],
            'url' => $url,
        ];
        $description = SeoHead::describe(self::str($product['short_description'] ?? null), self::str($product['description'] ?? null));
        if ($description !== null) {
            $data['description'] = $description;
        }
        if ($images !== []) {
            $data['image'] = array_map(fn (array $image): string => self::absolute($image['url']), $images);
        }
        if (is_array($product['brand'] ?? null)) {
            $data['brand'] = ['@type' => 'Brand', 'name' => (string) $product['brand']['name']];
        }
        if (count($variants) === 1) {
            $data['sku'] = (string) $variants[0]['sku_code'];
            if ($barcode !== null && self::isEan13($barcode)) {
                $data['gtin13'] = $barcode;
            }
        }

        $priced = array_values(array_filter($variants, fn (array $v): bool => is_array($v['price'] ?? null)));
        if ($priced !== []) {
            $data['offers'] = count($variants) === 1
                ? self::offer($priced[0], $url, $brand)
                : self::aggregateOffer($priced, $url, $brand);
        }

        return $data;
    }

    /**
     * @param  list<array{name: string, url: string}>  $trail  Home first, the current page last
     * @return array<string, mixed>
     */
    public static function breadcrumb(array $trail): array
    {
        $items = [];
        foreach ($trail as $i => $crumb) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $crumb['name'], 'item' => $crumb['url']];
        }

        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    /** @return array<string, mixed> */
    public static function organization(Branding $brand): array
    {
        $seller = $brand->seller;
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $brand->name,
            'url' => SeoHead::url('/'),
        ];
        if ($seller->legalName !== null) {
            $data['legalName'] = $seller->legalName;
        }
        $logo = $brand->toArray()['logo_url'] ?? null;
        if (is_string($logo)) {
            $data['logo'] = self::absolute($logo);
        }
        if ($seller->vatNumber !== null) {
            $data['vatID'] = $seller->vatNumber;
        }
        if ($seller->addressLines !== []) {
            $data['address'] = implode(', ', $seller->addressLines);
        }
        if ($brand->supportPhone !== null || $brand->supportEmail !== null) {
            $data['contactPoint'] = array_filter([
                '@type' => 'ContactPoint',
                'contactType' => 'customer service',
                'areaServed' => 'GB',
                'telephone' => $brand->supportPhone,
                'email' => $brand->supportEmail,
            ], fn ($v) => $v !== null);
        }

        return $data;
    }

    /**
     * The gross "each" price the page shows: `round_half_up(net_e4 × (10000
     * + rate_bp) / 10000)`, to the penny (05.15 §4.3), as "12.34".
     */
    public static function grossPrice(int $unitNetE4, int $taxRateBp): string
    {
        $grossE4 = Money::roundHalfUpDiv($unitNetE4 * (10000 + $taxRateBp), 10000);

        return (string) MoneyFormatter::minorToDecimalString(Money::roundHalfUpDiv($grossE4, 100));
    }

    /**
     * @param  array<string, mixed>  $variant
     * @return array<string, mixed>
     */
    private static function offer(array $variant, string $url, Branding $brand): array
    {
        /** @var array{unit_net_e4: int, tax_rate_bp: int} $price */
        $price = $variant['price'];
        $offer = [
            '@type' => 'Offer',
            'url' => $url,
            'price' => self::grossPrice((int) $price['unit_net_e4'], (int) $price['tax_rate_bp']),
            'priceCurrency' => 'GBP',
            'availability' => self::AVAILABILITY[(string) $variant['stock']] ?? self::AVAILABILITY[StockLabels::OUT_OF_STOCK],
            'itemCondition' => 'https://schema.org/NewCondition',
            'seller' => ['@type' => 'Organization', 'name' => $brand->name],
            'hasMerchantReturnPolicy' => self::returnPolicy(),
        ];

        $minimum = self::minimumBaseQty($variant);
        if ($minimum > 1) {
            $offer['eligibleQuantity'] = ['@type' => 'QuantitativeValue', 'minValue' => $minimum, 'unitCode' => 'C62'];
        }

        return $offer;
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $priced
     * @return array<string, mixed>
     */
    private static function aggregateOffer(array $priced, string $url, Branding $brand): array
    {
        $grossE4 = [];
        $labels = [];
        foreach ($priced as $variant) {
            /** @var array{unit_net_e4: int, tax_rate_bp: int} $price */
            $price = $variant['price'];
            $grossE4[] = [(int) $price['unit_net_e4'], (int) $price['tax_rate_bp'],
                Money::roundHalfUpDiv((int) $price['unit_net_e4'] * (10000 + (int) $price['tax_rate_bp']), 10000)];
            $labels[] = (string) $variant['stock'];
        }
        usort($grossE4, fn (array $a, array $b): int => $a[2] <=> $b[2]);
        $low = $grossE4[0];
        $high = $grossE4[count($grossE4) - 1];

        $best = StockLabels::OUT_OF_STOCK;
        foreach (self::LABEL_ORDER as $label) {
            if (in_array($label, $labels, true)) {
                $best = $label;
                break;
            }
        }

        return [
            '@type' => 'AggregateOffer',
            'url' => $url,
            'lowPrice' => self::grossPrice($low[0], $low[1]),
            'highPrice' => self::grossPrice($high[0], $high[1]),
            'offerCount' => count($priced),
            'priceCurrency' => 'GBP',
            'availability' => self::AVAILABILITY[$best],
            'itemCondition' => 'https://schema.org/NewCondition',
            'seller' => ['@type' => 'Organization', 'name' => $brand->name],
            'hasMerchantReturnPolicy' => self::returnPolicy(),
        ];
    }

    /** @return array<string, mixed> 05.15 §7.2: 14 days, by post, change-of-mind return postage paid by the buyer */
    private static function returnPolicy(): array
    {
        return [
            '@type' => 'MerchantReturnPolicy',
            'applicableCountry' => 'GB',
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays' => 14,
            'returnMethod' => 'https://schema.org/ReturnByMail',
            'returnFees' => 'https://schema.org/ReturnFeesCustomerResponsibility',
        ];
    }

    /**
     * The smallest quantity, in base units, one purchase can be: the MOQ
     * rounded up to whole default packs.
     *
     * @param  array<string, mixed>  $variant
     */
    private static function minimumBaseQty(array $variant): int
    {
        /** @var list<array{code: string, base_units: int}> $packs */
        $packs = $variant['packs'] ?? [];
        $packUnits = 1;
        foreach ($packs as $pack) {
            if ($pack['code'] === ($variant['default_pack_code'] ?? null)) {
                $packUnits = max(1, (int) $pack['base_units']);
            }
        }
        $moq = max(1, (int) ($variant['moq_base_qty'] ?? 1));

        return intdiv($moq + $packUnits - 1, $packUnits) * $packUnits;
    }

    /** A 13-digit EAN with a valid check digit (GS1 mod 10). */
    public static function isEan13(string $code): bool
    {
        if (preg_match('/^\d{13}$/', $code) !== 1) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $code[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10 === (int) $code[12];
    }

    private static function absolute(string $url): string
    {
        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://') ? $url : SeoHead::url($url);
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
