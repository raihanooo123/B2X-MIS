<?php

use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Warehouse\Exceptions\GoodsInRejectedException;
use App\Domain\Warehouse\GoodsInService;
use App\Domain\Warehouse\ReceiptSource;
use App\Domain\Warehouse\ReceiveLine;
use App\Domain\Warehouse\VarianceDecision;
use App\Models\Location;
use App\Models\NumberSequence;
use App\Models\Pack;
use App\Models\PurchaseOrder;
use App\Models\Sku;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->supplier = Supplier::factory()->create();
    $this->location = Location::factory()->default()->create();
    $this->sku = Sku::factory()->create();
    $this->pack = Pack::factory()->for($this->sku)->outer(12)->create();
    $this->service = app(PurchaseOrderService::class);
});

function poInput(array $changes = []): array
{
    return array_replace_recursive([
        'supplier_id' => test()->supplier->id,
        'location_id' => test()->location->id,
        'expected_at' => now()->addDays(7)->toDateString(),
        'lines' => [[
            'sku_id' => test()->sku->id,
            'pack_id' => test()->pack->id,
            'pack_qty' => 5,
            'unit_fob' => '1.2345',
        ]],
    ], $changes);
}

it('numbers a draft without creating stock and confirms exact incoming quantity', function () {
    $order = $this->service->createDraft(poInput(), null);

    expect($order->po_number)->toMatch('/^PO-\d{6}$/')
        ->and($order->status)->toBe('draft')
        ->and($order->currency)->toBe('GBP')
        ->and($order->goods_total_minor)->toBe(7407)
        ->and($order->goods_total_base_minor)->toBe(7407)
        ->and($order->lines()->sole()->base_qty)->toBe(60)
        ->and($order->lines()->sole()->unit_fob_e4)->toBe(12345)
        ->and(StockLevel::identity($this->sku->id, $this->location->id, null)->exists())->toBeFalse()
        ->and(StockMovement::query()->count())->toBe(0);

    $this->service->confirm($order);

    expect($order->refresh()->status)->toBe('confirmed')
        ->and(StockLevel::identity($this->sku->id, $this->location->id, null)->sole()->incoming_base_qty)->toBe(60)
        ->and(StockLevel::identity($this->sku->id, $this->location->id, null)->sole()->on_hand_base_qty)->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0);

    expect(fn () => $this->service->confirm($order))->toThrow(ValidationException::class);
    expect(StockLevel::identity($this->sku->id, $this->location->id, null)->sole()->incoming_base_qty)->toBe(60);
});

it('replaces draft lines and preserves the PO number without changing stock', function () {
    $order = $this->service->createDraft(poInput(), null);
    $number = $order->po_number;
    $updated = $this->service->updateDraft($order, poInput(['lines' => [[
        'sku_id' => $this->sku->id,
        'pack_id' => $this->pack->id,
        'pack_qty' => 2,
        'unit_fob' => '2.0000',
    ]]]));

    expect($updated->po_number)->toBe($number)
        ->and($updated->lines()->count())->toBe(1)
        ->and($updated->lines()->sole()->base_qty)->toBe(24)
        ->and($updated->goods_total_minor)->toBe(4800)
        ->and(StockLevel::identity($this->sku->id, $this->location->id, null)->exists())->toBeFalse();

    $this->service->confirm($updated);
    expect(fn () => $this->service->updateDraft($updated, poInput()))->toThrow(ValidationException::class);
});

it('receives against a confirmed PO and cancels only the remaining incoming stock', function () {
    $order = $this->service->createDraft(poInput(), null);
    $this->service->confirm($order);

    $goodsIn = app(GoodsInService::class);
    $receipt = $goodsIn->open(ReceiptSource::PurchaseOrder, $order->id, null, null, null);
    $goodsIn->receive($receipt, new ReceiveLine(
        clientToken: (string) Str::uuid(),
        purchaseOrderLineId: $order->lines()->sole()->id,
        skuId: $this->sku->id,
        packId: $this->pack->id,
        packQty: 2,
    ));

    $level = StockLevel::identity($this->sku->id, $this->location->id, null)->sole();
    expect($level->on_hand_base_qty)->toBe(24)
        ->and($level->incoming_base_qty)->toBe(36)
        ->and($order->lines()->sole()->received_base_qty)->toBe(24);

    $goodsIn->close($receipt, [VarianceDecision::remainderExpected($order->lines()->sole()->id)], null);
    $this->service->cancel($order);
    $level = StockLevel::identity($this->sku->id, $this->location->id, null)->sole();
    expect($order->refresh()->status)->toBe('cancelled')
        ->and($level->on_hand_base_qty)->toBe(24)
        ->and($level->incoming_base_qty)->toBe(0)
        ->and(StockMovement::query()->where('movement_type', 'goods_in')->count())->toBe(1);
});

it('refuses to book through a stale open receipt for a cancelled PO', function () {
    $order = $this->service->createDraft(poInput(), null);
    $this->service->confirm($order);
    $goodsIn = app(GoodsInService::class);
    $receipt = $goodsIn->open(ReceiptSource::PurchaseOrder, $order->id, null, null, null);

    $order->forceFill(['status' => 'cancelled'])->save();

    try {
        $goodsIn->receive($receipt, new ReceiveLine(
            clientToken: (string) Str::uuid(),
            purchaseOrderLineId: $order->lines()->sole()->id,
            skuId: $this->sku->id,
            packId: $this->pack->id,
            packQty: 1,
        ));
        $this->fail('Cancelled purchase orders must not accept stock through an open receipt.');
    } catch (GoodsInRejectedException $exception) {
        expect($exception->errorCode)->toBe('purchase_order_not_receivable');
    }

    expect(StockMovement::query()->where('movement_type', 'goods_in')->count())->toBe(0)
        ->and(StockLevel::identity($this->sku->id, $this->location->id, null)->sole()->on_hand_base_qty)->toBe(0)
        ->and(StockLevel::identity($this->sku->id, $this->location->id, null)->sole()->incoming_base_qty)->toBe(60);
});

it('does not cancel a PO while its Goods-in receipt is open', function () {
    $order = $this->service->createDraft(poInput(), null);
    $this->service->confirm($order);
    $receipt = app(GoodsInService::class)->open(ReceiptSource::PurchaseOrder, $order->id, null, null, null);

    expect(fn () => $this->service->cancel($order))->toThrow(ValidationException::class)
        ->and($order->refresh()->status)->toBe('confirmed')
        ->and(StockLevel::identity($this->sku->id, $this->location->id, null)->sole()->incoming_base_qty)->toBe(60);

    app(GoodsInService::class)->close($receipt, [], null);
    $this->service->cancel($order);
    expect($order->refresh()->status)->toBe('cancelled');
});

it('rejects invalid drafts without consuming a PO number', function () {
    $next = NumberSequence::query()->findOrFail('po_number')->next_value;
    $foreignPack = Pack::factory()->create();

    expect(fn () => $this->service->createDraft(poInput(['lines' => [[
        'sku_id' => $this->sku->id,
        'pack_id' => $foreignPack->id,
        'pack_qty' => 1,
        'unit_fob' => '1.0000',
    ]]]), null))->toThrow(ValidationException::class)
        ->and(PurchaseOrder::query()->count())->toBe(0)
        ->and(NumberSequence::query()->findOrFail('po_number')->next_value)->toBe($next);
});

it('rejects an inactive supplier and changed pack before confirmation', function () {
    $order = $this->service->createDraft(poInput(), null);
    $this->pack->update(['base_units' => 6]);
    expect(fn () => $this->service->confirm($order))->toThrow(ValidationException::class);

    $this->pack->update(['base_units' => 12]);
    $this->supplier->update(['status' => 'on_hold']);
    expect(fn () => $this->service->confirm($order))->toThrow(ValidationException::class)
        ->and($order->refresh()->status)->toBe('draft')
        ->and(StockLevel::identity($this->sku->id, $this->location->id, null)->exists())->toBeFalse();
});

it('rejects a supplier changed to a non-GBP currency before confirmation', function () {
    $order = $this->service->createDraft(poInput(), null);
    $this->supplier->update(['default_currency' => 'USD']);

    expect(fn () => $this->service->confirm($order))->toThrow(ValidationException::class)
        ->and($order->refresh()->status)->toBe('draft')
        ->and(StockLevel::identity($this->sku->id, $this->location->id, null)->exists())->toBeFalse();
});

it('rejects a line quantity beyond the integer stock projection range', function () {
    expect(fn () => $this->service->createDraft(poInput(['lines' => [[
        'sku_id' => $this->sku->id,
        'pack_id' => $this->pack->id,
        'pack_qty' => 2147483647,
        'unit_fob' => '0.0001',
    ]]]), null))->toThrow(ValidationException::class)
        ->and(PurchaseOrder::query()->count())->toBe(0);
});
