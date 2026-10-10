<?php

use App\Domain\Ordering\BulkEntry\BulkEntryImports;
use App\Domain\Ordering\BulkEntry\EntryParser;
use App\Domain\Ordering\BulkEntry\EntryRejected;
use App\Domain\Ordering\BulkEntry\Reconciler;
use App\Models\BulkEntryImport;
use App\Models\Cart;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\SavedList;
use App\Models\Sku;
use App\Models\StockLevel;
use App\Models\TaxClass;
use App\Models\TaxRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/**
 * 05.1 §14.3 — order-pad tools: paste and CSV parsing (separators, BOM,
 * quotes, header order, malformed and oversized input), reconciliation
 * (ambiguous codes, default packs, duplicate merge then MOQ, suggestions
 * never applied silently), confirmation (partial, merge into the basket,
 * accepted adjustments, basket/price/stock drift, expiry, double confirm),
 * queued imports with the captured company, foreign access, saved-list
 * versions and viewer policy, reorder minus cancellations, formula-safe
 * rejection export and constant queries.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Storage::fake(config('documents.disk'));
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-20 10:00:00', 'UTC'));
    $this->location = Location::factory()->default()->create();
    $this->taxClass = TaxClass::factory()->create();
    TaxRate::factory()->for($this->taxClass)->forPeriod(CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'), CarbonImmutable::parse('2030-01-01 00:00:00', 'UTC'))
        ->create(['country_code' => 'GB', 'rate_bp' => 2000]);
    $this->priceList = PriceList::factory()->create(['scope' => 'base', 'validity' => '[2026-01-01 00:00:00+00,)']);
});

afterEach(fn () => Carbon::setTestNow());

/** A priced, stocked SKU with a default "EACH" pack. */
function otlSku(string $code, array $overrides = [], int $stock = 1000, int $priceE4 = 10000): Sku
{
    $sku = Sku::factory()->create($overrides + ['sku_code' => $code, 'tax_class_id' => test()->taxClass->id]);
    Pack::factory()->for($sku)->create(['code' => 'EACH', 'base_units' => 1, 'is_default_sell' => true]);
    PriceListItem::factory()->for(test()->priceList, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => $priceE4]);
    StockLevel::factory()->for($sku)->for(test()->location)->create(['on_hand_base_qty' => $stock, 'allocated_base_qty' => 0]);

    return $sku;
}

/** @return array{0: Company, 1: User} */
function otlBuyer(string $role = 'buyer', ?Company $company = null): array
{
    $company ??= Company::factory()->create();
    $user = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => $role]);

    return [$company, $user];
}

function otlCart(Company $company, User $user): Cart
{
    return Cart::query()->firstOrCreate(['company_id' => $company->id, 'user_id' => $user->id], ['session_token' => bin2hex(random_bytes(16))]);
}

function otlStage(string $text): array
{
    $response = test()->postJson('/api/v1/order-imports', ['source' => 'paste', 'text' => $text])->assertCreated();

    return $response->json('data');
}

it('reads pasted lines with any separator, a missing quantity as one pack, and keeps bad lines with their row number', function () {
    $rows = (new EntryParser)->paste("ABC-1, 3\nABC-2  12\nABC-3;5\n\nABC-4\tABC-x 2\nABC-5\nABC-6 1.5\nABC-7 0\nABC-8 -2");

    expect(array_column($rows, 'row_no'))->toBe([1, 2, 3, 5, 6, 7, 8, 9])
        ->and(array_column($rows, 'pack_qty'))->toBe([3, 12, 5, null, null, null, null, null])
        ->and(array_column($rows, 'error_code'))->toBe([null, null, null, 'malformed', null, 'fractional_quantity', 'non_positive_quantity', 'non_positive_quantity']);
});

it('reads a UTF-8 CSV with a BOM, quoted fields and headers in any order and case', function () {
    $csv = "\xEF\xBB\xBF".'Quantity,PACK_CODE,Sku_Code'."\n".'2,,"ABC,1"'."\n\n".'"3","CASE","ABC-2"'."\n";

    $rows = (new EntryParser)->csv($csv);

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray(['row_no' => 2, 'sku_code' => 'ABC,1', 'pack_code' => null, 'pack_qty' => 2, 'error_code' => null])
        ->and($rows[1])->toMatchArray(['row_no' => 4, 'sku_code' => 'ABC-2', 'pack_code' => 'CASE', 'pack_qty' => 3]);
});

it('refuses a CSV without a sku_code header, not UTF-8, or over 5,000 rows, before staging', function (string $csv, string $reason) {
    expect(fn () => (new EntryParser)->csv($csv))->toThrow(EntryRejected::class)
        ->and(rescue(fn () => (new EntryParser)->csv($csv), fn (EntryRejected $e) => $e->reason, false))->toBe($reason);
})->with([
    'no header' => ["code,qty\nA,1\n", 'missing_header'],
    'not utf-8' => ["sku_code,quantity\n\xC3\x28,1\n", 'not_utf8'],
    'too many rows' => ["sku_code\n".str_repeat("A\n", 5001), 'too_many_rows'],
]);

it('reconciles: case-insensitive codes, ambiguous codes, default packs, inactive SKUs and close matches', function () {
    otlSku('ABC-1');
    otlSku('dup-1');
    otlSku('DUP-1');
    $nopack = Sku::factory()->create(['sku_code' => 'NOPACK', 'tax_class_id' => test()->taxClass->id]);
    Pack::factory()->for($nopack)->create(['code' => 'CASE', 'is_default_sell' => false]);
    otlSku('OLD-1', ['status' => 'discontinued']);

    $rows = (new Reconciler)->reconcile((new EntryParser)->paste("abc-1 2\nDup-1\nNOPACK\nOLD-1\nABC-2"), null);

    expect(array_column($rows, 'outcome'))->toBe(['ok', 'ambiguous', 'no_pack', 'inactive', 'not_found'])
        ->and($rows[0]['sku_code'])->toBe('ABC-1')
        ->and($rows[0]['pack_code'])->toBe('EACH')
        ->and($rows[4]['suggestions'])->toContain('ABC-1');
});

it('merges duplicates, then checks the minimum and case size on the merged quantity, suggesting rather than changing it', function () {
    otlSku('CASE-6', ['moq_base_qty' => 12, 'order_increment_base_qty' => 6]);

    $rows = (new Reconciler)->reconcile((new EntryParser)->paste("CASE-6 4\ncase-6 3\nCASE-6 1"), null);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['outcome' => 'adjust', 'pack_qty' => 8, 'suggested_pack_qty' => 12, 'error_code' => 'below_minimum', 'merged_row_nos' => [2, 3]]);
});

it('previews without touching the basket, then merges only the selected rows into it, once', function () {
    $a = otlSku('AAA-1');
    $b = otlSku('BBB-1');
    [$company, $buyer] = otlBuyer();
    $cart = otlCart($company, $buyer);
    DB::table('cart_lines')->insert(['public_id' => (string) Str::ulid(), 'cart_id' => $cart->id, 'sku_id' => $a->id, 'pack_id' => $a->packs()->value('id'), 'pack_qty' => 1, 'pack_base_units' => 1, 'base_qty' => 1]);

    $this->actingAs($buyer);
    $import = otlStage("AAA-1 2\nBBB-1 3\nZZZ-9 1");
    expect(DB::table('cart_lines')->where('cart_id', $cart->id)->count())->toBe(1);

    $cartVersion = app(BulkEntryImports::class)->cartVersion($cart);
    $payload = ['version' => $import['version'], 'rows' => [1], 'cart_version' => $cartVersion];
    $this->postJson("/api/v1/order-imports/{$import['id']}/confirm", $payload)->assertOk()->assertJsonPath('data.lines', 1);
    // A repeat returns the saved result and adds nothing more.
    $this->postJson("/api/v1/order-imports/{$import['id']}/confirm", $payload)->assertOk()->assertJsonPath('data.lines', 1);

    expect(DB::table('cart_lines')->where('cart_id', $cart->id)->where('sku_id', $a->id)->value('pack_qty'))->toBe(3)
        ->and(DB::table('cart_lines')->where('cart_id', $cart->id)->where('sku_id', $b->id)->exists())->toBeFalse();
});

it('adds an adjusted row only when the suggested quantity is accepted, and refuses rows that cannot be added', function () {
    otlSku('CASE-6', ['order_increment_base_qty' => 6]);
    [$company, $buyer] = otlBuyer();
    $cart = otlCart($company, $buyer);
    $this->actingAs($buyer);
    $import = otlStage("CASE-6 4\nNOPE 1");
    $cartVersion = app(BulkEntryImports::class)->cartVersion($cart);

    $this->postJson("/api/v1/order-imports/{$import['id']}/confirm", ['version' => $import['version'], 'rows' => [1], 'cart_version' => $cartVersion])
        ->assertStatus(422)->assertJsonPath('error.code', 'adjustment_not_accepted');
    $this->postJson("/api/v1/order-imports/{$import['id']}/confirm", ['version' => $import['version'], 'rows' => [2], 'cart_version' => $cartVersion])
        ->assertStatus(422)->assertJsonPath('error.code', 'row_not_selectable');
    $this->postJson("/api/v1/order-imports/{$import['id']}/confirm", ['version' => $import['version'], 'rows' => [1], 'accept' => [1], 'cart_version' => $cartVersion])->assertOk();

    expect(DB::table('cart_lines')->where('cart_id', $cart->id)->value('pack_qty'))->toBe(6);
});

it('refreshes the preview instead of adding when the basket, price or stock changed', function () {
    $sku = otlSku('AAA-1', [], 10);
    [$company, $buyer] = otlBuyer();
    $cart = otlCart($company, $buyer);
    $this->actingAs($buyer);
    $import = otlStage('AAA-1 2');
    $cartVersion = app(BulkEntryImports::class)->cartVersion($cart);

    // The basket changed in another tab.
    DB::table('cart_lines')->insert(['public_id' => (string) Str::ulid(), 'cart_id' => $cart->id, 'sku_id' => $sku->id, 'pack_id' => $sku->packs()->value('id'), 'pack_qty' => 1, 'pack_base_units' => 1, 'base_qty' => 1]);
    $this->postJson("/api/v1/order-imports/{$import['id']}/confirm", ['version' => $import['version'], 'rows' => [1], 'cart_version' => $cartVersion])
        ->assertStatus(409)->assertJsonPath('error.code', 'cart_changed');

    // The price changed: the row is refreshed, the version bumped, nothing added.
    $cartVersion = app(BulkEntryImports::class)->cartVersion($cart);
    DB::table('price_list_items')->where('sku_id', $sku->id)->update(['unit_price_e4' => 12000]);
    $this->postJson("/api/v1/order-imports/{$import['id']}/confirm", ['version' => $import['version'], 'rows' => [1], 'cart_version' => $cartVersion])
        ->assertStatus(409)->assertJsonPath('error.code', 'preview_changed');
    expect(BulkEntryImport::query()->sole()->version)->toBe($import['version'] + 1)
        ->and(DB::table('cart_lines')->where('cart_id', $cart->id)->value('pack_qty'))->toBe(1);
});

it('adds nothing from an expired import, and purges its input', function () {
    otlSku('AAA-1');
    [$company, $buyer] = otlBuyer();
    $cart = otlCart($company, $buyer);
    $this->actingAs($buyer);
    $import = otlStage('AAA-1 2');

    Carbon::setTestNow(now()->addHours(25));
    $this->postJson("/api/v1/order-imports/{$import['id']}/confirm", ['version' => $import['version'], 'rows' => [1], 'cart_version' => app(BulkEntryImports::class)->cartVersion($cart)])
        ->assertStatus(409)->assertJsonPath('error.code', 'import_expired');

    expect(app(BulkEntryImports::class)->purgeExpired())->toBe(1)
        ->and(BulkEntryImport::query()->sole()->rows)->toBe([])
        ->and(DB::table('cart_lines')->count())->toBe(0);

    Carbon::setTestNow(now()->addDays(8));
    app(BulkEntryImports::class)->purgeExpired();
    expect(BulkEntryImport::query()->count())->toBe(0);
});

it('checks a large import on the queue with the company captured at staging', function () {
    otlSku('AAA-1');
    [$company, $buyer] = otlBuyer();
    $this->actingAs($buyer);

    $import = otlStage(str_repeat("AAA-1 1\n", 600));

    $stored = BulkEntryImport::query()->sole();
    expect($stored->company_id)->toBe($company->id)
        ->and($stored->status)->toBe('ready')
        ->and($import['row_count'])->toBe(1)
        ->and($stored->rows[0]['pack_qty'])->toBe(600);
});

it('accepts a CSV upload of at most 5 MB and stores it privately', function () {
    otlSku('AAA-1');
    [, $buyer] = otlBuyer();
    $this->actingAs($buyer);

    $this->post('/api/v1/order-imports', ['source' => 'csv', 'file' => UploadedFile::fake()->createWithContent('order.csv', "sku_code,quantity\nAAA-1,2\n")], ['Accept' => 'application/json'])->assertCreated();
    $path = BulkEntryImport::query()->sole()->private_storage_path;
    Storage::disk(config('documents.disk'))->assertExists((string) $path);

    $this->post('/api/v1/order-imports', ['source' => 'csv', 'file' => UploadedFile::fake()->create('big.csv', 6000, 'text/csv')], ['Accept' => 'application/json'])->assertStatus(422);
});

it('exports the problem rows with formula-like cells neutralised', function () {
    [, $buyer] = otlBuyer();
    $this->actingAs($buyer);
    $import = otlStage("=HYPERLINK(\"x\") 1\n@SUM 2");

    $csv = (string) $this->get("/trade/order-tools/imports/{$import['id']}/problems.csv")->assertOk()->getContent();

    expect($csv)->toContain("'=HYPERLINK")->toContain("'@SUM")->not->toContain(',=HYPERLINK');
});

it('keeps imports, lists and orders to their own company and user, and lists read-only for viewers', function () {
    otlSku('AAA-1');
    [$company, $buyer] = otlBuyer();
    [, $colleague] = otlBuyer('owner', $company);
    [$other, $stranger] = otlBuyer();
    [, $viewer] = otlBuyer('viewer', $company);
    $this->actingAs($buyer);
    $import = otlStage('AAA-1 1');
    $list = SavedList::query()->create(['company_id' => $company->id, 'name' => 'Weekly', 'created_by_user_id' => $buyer->id]);
    $theirOrder = Order::factory()->create(['company_id' => $other->id]);

    $this->actingAs($colleague)->getJson("/api/v1/order-imports/{$import['id']}")->assertNotFound();
    $this->actingAs($stranger)->getJson("/api/v1/order-imports/{$import['id']}")->assertNotFound();
    $this->actingAs($stranger)->get("/trade/saved-lists/{$list->public_id}")->assertNotFound();
    $this->actingAs($buyer)->postJson("/api/v1/orders/{$theirOrder->public_id}/reorder-preview")->assertNotFound();

    $this->actingAs($viewer)->get("/trade/saved-lists/{$list->public_id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Trade/SavedLists/Show', false)->where('can_edit', false));
    $this->actingAs($viewer)->postJson("/api/v1/saved-lists/{$list->public_id}/preview")->assertForbidden();
    $this->actingAs($viewer)->postJson('/api/v1/order-imports', ['source' => 'paste', 'text' => 'AAA-1'])->assertForbidden();
    $this->actingAs($viewer)->patchJson("/api/v1/saved-lists/{$list->public_id}", ['version' => 0, 'name' => 'Mine'])->assertForbidden();
});

it('refuses a saved-list edit made against a stale version, so colleagues never overwrite each other', function () {
    $sku = otlSku('AAA-1');
    [$company, $buyer] = otlBuyer();
    [, $colleague] = otlBuyer('owner', $company);
    $cart = otlCart($company, $buyer);
    DB::table('cart_lines')->insert(['public_id' => (string) Str::ulid(), 'cart_id' => $cart->id, 'sku_id' => $sku->id, 'pack_id' => $sku->packs()->value('id'), 'pack_qty' => 4, 'pack_base_units' => 1, 'base_qty' => 4]);

    $this->actingAs($buyer);
    $listId = $this->postJson('/api/v1/saved-lists', ['name' => 'Weekly', 'from_cart' => true])->assertCreated()->assertJsonPath('data.lines.0.pack_qty', 4)->json('data.id');

    $this->actingAs($colleague)->patchJson("/api/v1/saved-lists/{$listId}", ['version' => 0, 'name' => 'Weekly cleaning'])->assertOk()->assertJsonPath('data.version', 1);
    $this->actingAs($buyer)->patchJson("/api/v1/saved-lists/{$listId}", ['version' => 0, 'name' => 'Old name'])->assertStatus(409)->assertJsonPath('error.code', 'list_changed');
    $this->actingAs($buyer)->deleteJson("/api/v1/saved-lists/{$listId}", ['version' => 0])->assertStatus(409);

    expect(SavedList::query()->sole()->name)->toBe('Weekly cleaning');
});

it('reorders what was ordered minus what was cancelled, flagging what is no longer sold', function () {
    $kept = otlSku('AAA-1');
    $gone = otlSku('OLD-1');
    [$company, $buyer] = otlBuyer();
    $order = Order::factory()->create(['company_id' => $company->id, 'placed_at' => now()->subDay()]);
    OrderLine::factory()->create(['order_id' => $order->id, 'line_no' => 1, 'sku_id' => $kept->id, 'pack_id' => $kept->packs()->value('id'), 'pack_qty' => 5, 'pack_base_units' => 1, 'base_qty' => 5, 'cancelled_base_qty' => 2, 'returned_base_qty' => 1]);
    OrderLine::factory()->create(['order_id' => $order->id, 'line_no' => 2, 'sku_id' => $gone->id, 'pack_id' => $gone->packs()->value('id'), 'pack_qty' => 1, 'pack_base_units' => 1, 'base_qty' => 1]);
    DB::table('skus')->where('id', $gone->id)->update(['status' => 'discontinued']);

    $this->actingAs($buyer);
    $rows = $this->postJson("/api/v1/orders/{$order->public_id}/reorder-preview")->assertCreated()->json('data.rows');

    expect($rows[0])->toMatchArray(['sku_code' => 'AAA-1', 'pack_qty' => 3, 'outcome' => 'ok'])
        ->and($rows[1])->toMatchArray(['sku_code' => 'OLD-1', 'outcome' => 'inactive']);
});

it('keeps queries constant as the number of rows grows', function () {
    foreach (range(1, 60) as $i) {
        otlSku(sprintf('Q-%03d', $i));
    }
    $count = function (int $rows): int {
        $text = implode("\n", array_map(fn (int $i) => sprintf('Q-%03d 1', ($i % 60) + 1), range(1, $rows)))."\nMISSING 1";
        DB::flushQueryLog();
        DB::enableQueryLog();
        (new Reconciler)->reconcile((new EntryParser)->paste($text), null);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $count(10);
    expect($count(100))->toBe($count(500));
});
