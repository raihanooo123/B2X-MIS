<?php

use App\Models\Address;
use App\Models\CartLine;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Location;
use App\Models\OrderSpendBreak;
use App\Models\Pack;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\PriceTier;
use App\Models\Sku;
use App\Models\StockLevel;
use App\Models\TaxClass;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

/**
 * Doc 05.1 §11 / §12.4: the order pad's client-computed totals equal the
 * server's re-resolution exactly — "any mismatch is a test failure, not
 * a tolerance."
 *
 * Both sides run for real. The server side is POST /checkout/preview on a
 * cart built through the cart API. The client side is the pad's own
 * resources/js/lib/pricing/localRecompute.ts, executed by Node (via
 * tests/js/recompute-basket.ts) over exactly what the page receives: the
 * /pricing/bulk-resolve response and the order pad's `totals_context`
 * prop. Compared line by line — unit price, price source, apportioned
 * spend discount, net, VAT — not only in total, so an error cannot hide
 * behind a compensating one.
 *
 * The catalogue is built to exercise every path the client must mirror:
 * sub-penny unit prices, base/tier/customer/contract precedence, a
 * contract list whose only row starts at 50 (03 §4.3 fall-through, which
 * a single list's ladder cannot reproduce), three VAT rates, tier breaks
 * that exclude contract lines, a company break that includes them and is
 * capped by max_discount_minor, and a global break the tier always
 * outranks. Coverage assertions at the end prove the baskets reached
 * those paths rather than passing vacuously.
 */
function parityNodeBinary(): ?string
{
    $node = (new ExecutableFinder)->find('node');
    if ($node === null) {
        return null;
    }

    $version = new Process([$node, '--version']);
    $version->run();
    if (! $version->isSuccessful() || preg_match('/^v(\d+)\.(\d+)/', trim($version->getOutput()), $m) !== 1) {
        return null;
    }

    // Type stripping is on by default from 22.18 and 23.6.
    [$major, $minor] = [(int) $m[1], (int) $m[2]];

    return $major > 23 || ($major === 23 && $minor >= 6) || ($major === 22 && $minor >= 18) ? $node : null;
}

it('computes client totals identical to checkout preview, line by line, for the same baskets', function () {
    $node = parityNodeBinary();
    if ($node === null) {
        $this->markTestSkipped('Needs Node >= 22.18 / 23.6 on PATH to run the TypeScript calculator.');
    }

    $location = Location::factory()->default()->create();
    $tier = PriceTier::factory()->create();
    $company = Company::factory()->create(['price_tier_id' => $tier->id, 'credit_limit_minor' => 1_000_000_000]);
    $user = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $user->id]);
    Address::factory()->default()->delivery()->create(['company_id' => $company->id, 'country_code' => 'GB']);

    $lists = [
        'base' => PriceList::factory()->create(['scope' => 'base']),
        'tier' => PriceList::factory()->create(['scope' => 'tier', 'price_tier_id' => $tier->id]),
        'customer' => PriceList::factory()->create(['scope' => 'company', 'company_id' => $company->id, 'has_contract' => false]),
        'contract' => PriceList::factory()->create(['scope' => 'company', 'company_id' => $company->id, 'has_contract' => true]),
    ];

    $taxClass = function (int $rateBp): TaxClass {
        $class = TaxClass::factory()->create();
        TaxRate::factory()->for($class)->create(['country_code' => 'GB', 'rate_bp' => $rateBp]);

        return $class;
    };
    $standard = $taxClass(2000);
    $reduced = $taxClass(500);
    $zero = $taxClass(0);

    // [tax class, pack sizes, list => [[min_base_qty, unit_price_e4], ...]]
    $catalogue = [
        [$standard, [1, 144], ['base' => [[1, 9800], [144, 9212], [1440, 8600]]]],
        [$standard, [1, 12], ['base' => [[1, 45000], [24, 42500]], 'tier' => [[1, 43333], [60, 39999]]]],
        [$reduced, [1, 10], ['base' => [[1, 12345]], 'tier' => [[1, 11999]], 'contract' => [[50, 10001]]]],
        [$zero, [1], ['base' => [[1, 250000]], 'contract' => [[1, 199999], [10, 187654]]]],
        [$standard, [1, 6], ['base' => [[1, 3333], [36, 3125]], 'customer' => [[100, 2999]]]],
        [$zero, [1, 48], ['base' => [[1, 1234], [96, 1111], [480, 999]]]],
        [$reduced, [1], ['base' => [[1, 77777]], 'customer' => [[1, 70001], [5, 65432]]]],
    ];

    $skus = [];
    foreach ($catalogue as [$class, $packSizes, $ladders]) {
        $sku = Sku::factory()->create(['tax_class_id' => $class->id]);
        $packs = [];
        foreach ($packSizes as $units) {
            $packs[] = $units === 1
                ? Pack::factory()->for($sku)->create()
                : Pack::factory()->for($sku)->outer($units)->create();
        }
        foreach ($ladders as $list => $rungs) {
            foreach ($rungs as [$min, $e4]) {
                PriceListItem::factory()->for($lists[$list], 'priceList')->for($sku)->create(['min_base_qty' => $min, 'unit_price_e4' => $e4]);
            }
        }
        StockLevel::factory()->for($sku)->for($location)->create(['on_hand_base_qty' => 10_000_000, 'allocated_base_qty' => 0]);
        $skus[] = ['sku' => $sku, 'packs' => $packs];
    }

    OrderSpendBreak::factory()->create(['code' => 'global-3pc-1000', 'scope' => 'global', 'min_subtotal_minor' => 100000, 'discount_type' => 'percentage', 'discount_rate_bp' => 300]);
    OrderSpendBreak::factory()->create(['code' => 'tier-25-off-400', 'scope' => 'tier', 'price_tier_id' => $tier->id, 'min_subtotal_minor' => 40000, 'discount_type' => 'fixed', 'discount_rate_bp' => null, 'discount_amount_minor' => 2500]);
    OrderSpendBreak::factory()->create(['code' => 'tier-3pc-1500', 'scope' => 'tier', 'price_tier_id' => $tier->id, 'min_subtotal_minor' => 150000, 'discount_type' => 'percentage', 'discount_rate_bp' => 333]);
    OrderSpendBreak::factory()->create(['code' => 'company-4pc-2500', 'scope' => 'company', 'company_id' => $company->id, 'min_subtotal_minor' => 250000, 'discount_type' => 'percentage', 'discount_rate_bp' => 400, 'max_discount_minor' => 9000, 'applies_to_contract_lines' => true]);

    // Hand-picked baskets for the paths that matter, then generated ones.
    // Each line is [catalogue index, pack index, pack qty]; SKUs are
    // distinct within a basket (a repeat would merge into one cart line).
    $baskets = [
        [[0, 0, 1]],
        [[2, 0, 49]],
        [[2, 1, 5]],
        [[1, 0, 12]],
        [[0, 1, 15]],
        [[3, 0, 100], [1, 0, 200]],
        [[0, 1, 3], [1, 1, 5], [2, 1, 7], [4, 1, 20], [5, 1, 11], [6, 0, 3]],
    ];

    mt_srand(20260924);
    for ($i = 0; $i < 40; $i++) {
        $indices = range(0, count($catalogue) - 1);
        shuffle($indices);
        $basket = [];
        foreach (array_slice($indices, 0, mt_rand(1, count($catalogue))) as $index) {
            $packIndex = mt_rand(0, count($skus[$index]['packs']) - 1);
            $qty = match (mt_rand(0, 2)) {
                0 => mt_rand(1, 9),
                1 => mt_rand(10, 60),
                default => mt_rand(61, 400),
            };
            $basket[] = [$index, $packIndex, $qty];
        }
        $baskets[] = $basket;
    }

    $this->withoutVite()->actingAs($user);

    $totalsContext = $this->get('/order-pad')->assertOk()->viewData('page')['props']['totals_context'];

    $entries = $this->postJson('/api/v1/pricing/bulk-resolve', [
        'sku_ids' => array_map(fn (array $s) => $s['sku']->public_id, $skus),
        'include_breaks' => true,
    ])->assertOk()->json('data');

    $server = [];
    $clientInput = [];
    foreach ($baskets as $basket) {
        CartLine::query()->delete();

        $lines = [];
        foreach ($basket as [$index, $packIndex, $packQty]) {
            $pack = $skus[$index]['packs'][$packIndex];
            $this->postJson('/api/v1/cart/lines', [
                'sku_id' => $skus[$index]['sku']->public_id,
                'pack_code' => $pack->code,
                'pack_qty' => $packQty,
            ])->assertSuccessful();
            $lines[] = ['sku_id' => $skus[$index]['sku']->public_id, 'pack_qty' => $packQty, 'pack_base_units' => $pack->base_units];
        }
        $clientInput[] = $lines;

        $preview = $this->postJson('/api/v1/checkout/preview', [])->assertOk()->json();

        $server[] = [
            'subtotal_net_minor' => $preview['subtotal_net_minor'],
            'tax_minor' => $preview['tax_minor'],
            'total_gross_minor' => $preview['total_gross_minor'],
            'spend_break' => $preview['spend_break'] === null ? null : [
                'code' => $preview['spend_break']['code'],
                'discount_minor' => $preview['spend_break']['discount_minor'],
            ],
            'lines' => array_map(fn (array $l) => [
                'sku_id' => $l['sku_id'],
                'base_qty' => $l['base_qty'],
                'unit_price_net_e4' => $l['unit_price_net_e4'],
                'price_source' => $l['price_source'],
                'line_spend_discount_minor' => $l['line_spend_discount_minor'],
                'line_net_minor' => $l['line_net_minor'],
                'tax_rate_bp' => $l['tax_rate_bp'],
                'line_tax_minor' => $l['line_tax_minor'],
                'line_gross_minor' => $l['line_gross_minor'],
            ], $preview['lines']),
        ];
    }

    $process = new Process([$node, '--no-warnings', base_path('tests/js/recompute-basket.ts')], base_path());
    $process->setInput(json_encode(['context' => $totalsContext, 'entries' => $entries, 'baskets' => $clientInput], JSON_THROW_ON_ERROR));
    $process->setTimeout(60);
    $process->mustRun();

    $client = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($client)->toHaveCount(count($server));
    foreach ($server as $i => $expected) {
        expect($client[$i]['unpriced'])->toBe([], "basket {$i} has unpriced lines");
        unset($client[$i]['unpriced']);
        expect($client[$i])->toBe($expected, "basket {$i} differs from checkout preview");
    }

    // Coverage: the baskets reached every path the calculator mirrors.
    $allLines = array_merge(...array_column($server, 'lines'));
    $breakCodes = array_filter(array_map(fn (array $s) => $s['spend_break']['code'] ?? null, $server));
    $sources = array_unique(array_column($allLines, 'price_source'));

    expect($breakCodes)->toContain('tier-25-off-400', 'tier-3pc-1500', 'company-4pc-2500')
        ->and(in_array(null, array_column($server, 'spend_break'), true))->toBeTrue()
        ->and($sources)->toContain('base', 'tier', 'customer', 'contract')
        ->and(array_filter($server, fn (array $s) => ($s['spend_break']['discount_minor'] ?? null) === 9000))->not->toBeEmpty()
        ->and(array_filter($allLines, fn (array $l) => $l['price_source'] === 'contract' && $l['line_spend_discount_minor'] === 0 && $l['line_net_minor'] > 0))->not->toBeEmpty()
        ->and(array_filter($allLines, fn (array $l) => $l['price_source'] === 'contract' && $l['line_spend_discount_minor'] > 0))->not->toBeEmpty();

    // 03 §4.3 fall-through: the same SKU priced by tier below 50 units and by contract from 50.
    $fallThroughSku = $skus[2]['sku']->public_id;
    $fallThrough = array_filter($allLines, fn (array $l) => $l['sku_id'] === $fallThroughSku);
    expect(array_column(array_filter($fallThrough, fn (array $l) => $l['base_qty'] < 50), 'price_source'))->each->toBe('tier')
        ->and(array_column(array_filter($fallThrough, fn (array $l) => $l['base_qty'] >= 50), 'price_source'))->each->toBe('contract');
});

it('exposes the spend-break table in selection order, and the carriage-paid threshold', function () {
    $tier = PriceTier::factory()->create();
    $company = Company::factory()->create(['price_tier_id' => $tier->id]);
    $other = Company::factory()->create();
    $user = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $user->id]);

    OrderSpendBreak::factory()->create(['code' => 'global-low', 'scope' => 'global', 'min_subtotal_minor' => 50000]);
    OrderSpendBreak::factory()->create(['code' => 'global-high', 'scope' => 'global', 'min_subtotal_minor' => 150000]);
    OrderSpendBreak::factory()->create(['code' => 'tier', 'scope' => 'tier', 'price_tier_id' => $tier->id, 'min_subtotal_minor' => 100000]);
    OrderSpendBreak::factory()->create(['code' => 'mine', 'scope' => 'company', 'company_id' => $company->id, 'min_subtotal_minor' => 300000]);
    OrderSpendBreak::factory()->create(['code' => 'someone-else', 'scope' => 'company', 'company_id' => $other->id, 'min_subtotal_minor' => 10000]);
    OrderSpendBreak::factory()->create(['code' => 'draft', 'scope' => 'global', 'min_subtotal_minor' => 20000, 'status' => 'draft']);

    $props = $this->withoutVite()->actingAs($user)->get('/order-pad')->assertOk()->viewData('page')['props']['totals_context'];

    expect(array_column($props['spend_breaks'], 'code'))->toBe(['mine', 'tier', 'global-high', 'global-low'])
        ->and($props['carriage_paid_threshold_net_minor'])->toBe(50000)
        ->and(array_keys($props['spend_breaks'][0]))->not->toContain('id', 'company_id', 'price_tier_id');

    auth()->forgetGuards();
    $guest = $this->get('/order-pad')->assertOk()->viewData('page')['props']['totals_context'];
    expect(array_column($guest['spend_breaks'], 'code'))->toBe(['global-high', 'global-low']);
});
