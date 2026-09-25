<?php

use App\Domain\Billing\Documents\InvoiceDocumentBuilder;
use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\ShipmentInvoiceShares;
use App\Domain\Inventory\AllocationLine;
use App\Domain\Inventory\AllocationService;
use App\Domain\Warehouse\DispatchDetails;
use App\Domain\Warehouse\DispatchService;
use App\Domain\Warehouse\PickConfirmationService;
use App\Domain\Warehouse\PickListGenerator;
use App\Models\Company;
use App\Models\CreditHold;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\NumberSequence;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\Shipment;
use App\Models\ShipmentLine;
use App\Models\StockLevel;
use App\Models\SystemConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Per-shipment invoicing — 05.5 §7.3 as amended 2026-09-25: pro-rata by
 * base quantity with the remainder on the shipment completing each line,
 * carriage on the first invoice, and the credit hold converted invoice by
 * invoice (05.2 §8.3).
 */
beforeEach(function () {
    Queue::fake();
    NumberSequence::factory()->forSeries('invoice_number', 'INV-')->create(['next_value' => 1, 'padding' => 6]);
    foreach ([
        'seller.legal_name' => 'Test Wholesale Ltd',
        'seller.address' => "1 Test Street\nLondon\nE1 6AN",
        'seller.vat_number' => 'GB123456789',
    ] as $key => $value) {
        SystemConfiguration::factory()->create(['config_key' => $key, 'value_type' => 'text', 'value_int' => null, 'value_text' => $value]);
    }
    $this->location = Location::factory()->default()->create();
    $this->invoices = new InvoiceService;
});

/**
 * An on-account order: one line of 3 units at £3.33⅓ net (1000p for the
 * line, 200p VAT), £5.00 carriage (100p VAT), and its credit hold for the
 * whole 1500p total — amounts chosen so thirds do not divide evenly.
 *
 * @return array{order: Order, line: OrderLine, company: Company, hold: CreditHold}
 */
function billingOrder(int $shippingNet = 500, int $shippingTax = 100): array
{
    $company = Company::factory()->create(['payment_terms' => 'net30', 'credit_limit_minor' => 1_000_000, 'credit_held_minor' => 1200 + $shippingNet + $shippingTax, 'credit_used_minor' => 0]);
    $order = Order::factory()->create([
        'company_id' => $company->id,
        'status' => 'picking',
        'payment_method' => 'on_account',
        'payment_status' => 'on_account',
        'subtotal_net_minor' => 1000,
        'shipping_net_minor' => $shippingNet,
        'shipping_tax_minor' => $shippingTax,
        'shipping_tax_rate_bp' => 2000,
        'tax_minor' => 200 + $shippingTax,
        'total_gross_minor' => 1200 + $shippingNet + $shippingTax,
    ]);
    $line = OrderLine::factory()->for($order)->forPack(Pack::factory()->create(), 3)->create([
        'unit_price_net_e4' => 33333,
        'line_net_minor' => 1000,
        'tax_rate_bp' => 2000,
        'line_tax_minor' => 200,
        'line_gross_minor' => 1200,
    ]);
    $hold = CreditHold::factory()->create(['company_id' => $company->id, 'order_id' => $order->id, 'amount_minor' => 1200 + $shippingNet + $shippingTax]);

    return compact('order', 'line', 'company', 'hold');
}

/** A dispatched shipment carrying `$qty` of the line, `$minutesAgo` before now. */
function billingShipment(array $f, int $qty, int $minutesAgo = 0): Shipment
{
    $shipment = Shipment::factory()->dispatched()->create([
        'order_id' => $f['order']->id,
        'location_id' => test()->location->id,
        'dispatched_at' => now()->subMinutes($minutesAgo),
    ]);
    ShipmentLine::factory()->create(['shipment_id' => $shipment->id, 'order_line_id' => $f['line']->id, 'dispatched_base_qty' => $qty]);
    OrderLine::query()->whereKey($f['line']->id)->increment('dispatched_base_qty', $qty);

    return $shipment;
}

it('telescopes: shares of each shipment sum to the line total exactly', function () {
    expect(ShipmentInvoiceShares::share(1000, 0, 1, 3))->toBe(333)
        ->and(ShipmentInvoiceShares::share(1000, 1, 2, 3))->toBe(334)
        ->and(ShipmentInvoiceShares::share(1000, 2, 3, 3))->toBe(333)
        ->and(ShipmentInvoiceShares::share(1000, 0, 3, 3))->toBe(1000)
        ->and(ShipmentInvoiceShares::share(200, 0, 1, 3) + ShipmentInvoiceShares::share(200, 1, 3, 3))->toBe(200);
});

it('invoices each shipment its share, carriage on the first, and the invoices sum to the order', function () {
    $f = billingOrder();
    $first = billingShipment($f, 1, 10);
    $f['order']->forceFill(['status' => 'part_dispatched'])->save();
    $one = $this->invoices->issueForShipment($first->id);

    $second = billingShipment($f, 2);
    $f['order']->forceFill(['status' => 'dispatched'])->save();
    $two = $this->invoices->issueForShipment($second->id);

    expect($one->shipment_id)->toBe($first->id)
        ->and($one->subtotal_net_minor)->toBe(333)
        ->and($one->shipping_net_minor)->toBe(500)
        ->and($one->tax_minor)->toBe(67 + 100)
        ->and($one->total_gross_minor)->toBe(333 + 500 + 167)
        ->and($two->subtotal_net_minor)->toBe(667)
        ->and($two->shipping_net_minor)->toBe(0)
        ->and($two->tax_minor)->toBe(133)
        ->and($one->subtotal_net_minor + $two->subtotal_net_minor)->toBe($f['order']->subtotal_net_minor)
        ->and($one->tax_minor + $two->tax_minor)->toBe($f['order']->tax_minor)
        ->and($one->total_gross_minor + $two->total_gross_minor)->toBe($f['order']->total_gross_minor)
        ->and($one->payment_terms)->toBe('net30');
});

it('orders shipments by dispatch time, not id, when apportioning', function () {
    $f = billingOrder();
    $later = billingShipment($f, 2, 0);   // created first, dispatched second
    $earlier = billingShipment($f, 1, 30);

    $shares = fn (Shipment $s) => (new ShipmentInvoiceShares)->forShipment($s)[0]->netMinor;

    expect($shares($earlier))->toBe(333)->and($shares($later))->toBe(667);
});

it('converts the credit hold invoice by invoice, consuming it on the last', function () {
    $f = billingOrder();
    $first = billingShipment($f, 1, 10);
    $f['order']->forceFill(['status' => 'part_dispatched'])->save();
    $one = $this->invoices->issueForShipment($first->id);

    $hold = $f['hold']->fresh();
    $company = $f['company']->fresh();
    expect($hold->status)->toBe('held')
        ->and($hold->amount_minor)->toBe(1800 - $one->total_gross_minor)
        ->and($hold->invoice_id)->toBe($one->id)
        ->and($company->credit_held_minor)->toBe(1800 - $one->total_gross_minor)
        ->and($company->credit_used_minor)->toBe($one->total_gross_minor);

    $second = billingShipment($f, 2);
    $f['order']->forceFill(['status' => 'dispatched'])->save();
    $two = $this->invoices->issueForShipment($second->id);

    $company->refresh();
    expect($f['hold']->fresh()->status)->toBe('invoiced')
        ->and($company->credit_held_minor)->toBe(0)
        ->and($company->credit_used_minor)->toBe(1800)
        ->and($one->total_gross_minor + $two->total_gross_minor)->toBe(1800);
});

it('is idempotent per shipment, and never bills an order that already has a whole-order invoice', function () {
    $f = billingOrder();
    $shipment = billingShipment($f, 3);
    $f['order']->forceFill(['status' => 'dispatched'])->save();

    $first = $this->invoices->issueForShipment($shipment->id);
    $again = $this->invoices->issueForShipment($shipment->id);
    expect($again->id)->toBe($first->id)->and(Invoice::query()->count())->toBe(1);

    $g = billingOrder();
    $this->invoices->issueForOrder($g['order']->id);
    $gShipment = billingShipment($g, 3);
    expect($this->invoices->issueForShipment($gShipment->id))->toBeNull()
        ->and(Invoice::query()->where('order_id', $g['order']->id)->count())->toBe(1);
});

it('builds a per-shipment document that adds up, lines at the shipped quantity', function () {
    $f = billingOrder();
    $shipment = billingShipment($f, 1);
    $f['order']->forceFill(['status' => 'part_dispatched'])->save();
    $invoice = $this->invoices->issueForShipment($shipment->id);

    $document = (new InvoiceDocumentBuilder)->build($invoice)->toArray();

    expect($document['lines'])->toHaveCount(1)
        ->and($document['lines'][0]['base_qty'])->toBe(1)
        ->and($document['lines'][0]['pack_qty'])->toBe(1)
        ->and($document['lines'][0]['line_net_minor'])->toBe(333)
        ->and($document['lines'][0]['line_vat_minor'])->toBe(67)
        ->and($document['carriage'])->not->toBeNull();
});

it('under on_completion invoices nothing on a partial dispatch and the whole order once complete', function () {
    SystemConfiguration::factory()->create(['config_key' => InvoiceService::MODE_KEY, 'value_type' => 'text', 'value_int' => null, 'value_text' => InvoiceService::MODE_ON_COMPLETION]);
    $f = billingOrder();
    $first = billingShipment($f, 1, 10);
    $f['order']->forceFill(['status' => 'part_dispatched'])->save();

    $this->invoices->whenDispatched($first->id);
    expect(Invoice::query()->count())->toBe(0);

    $second = billingShipment($f, 2);
    $f['order']->forceFill(['status' => 'dispatched'])->save();
    $this->invoices->whenDispatched($second->id);

    $invoice = Invoice::query()->sole();
    expect($invoice->shipment_id)->toBeNull()
        ->and($invoice->total_gross_minor)->toBe(1800)
        ->and($f['hold']->fresh()->status)->toBe('invoiced');
});

it('invoices an on-account shipment when it is dispatched', function () {
    $f = billingOrder(0, 0);
    StockLevel::factory()->for($f['line']->sku)->for($this->location)->create(['on_hand_base_qty' => 10, 'allocated_base_qty' => 0]);
    (new AllocationService)->allocate(null, 0, [new AllocationLine($f['line']->id, $f['line']->sku_id, $this->location->id, null, 3)]);
    $generator = new PickListGenerator;
    $shipment = $generator->open($f['order']->id, $this->location->id);
    (new PickConfirmationService)->confirm($shipment, $generator->generate($shipment)->lines[0]->allocationId);

    (new DispatchService)->dispatch($shipment, new DispatchDetails('DPD'));

    $invoice = Invoice::query()->sole();
    expect($invoice->shipment_id)->toBe($shipment->id)
        ->and($invoice->total_gross_minor)->toBe(1200)
        ->and($f['hold']->fresh()->status)->toBe('invoiced')
        ->and($f['company']->fresh()->credit_used_minor)->toBe(1200);
});

it('leaves a prepaid order to the invoice it got at payment', function () {
    $f = billingOrder();
    $f['order']->forceFill(['payment_method' => 'card', 'payment_status' => 'paid'])->save();
    $shipment = billingShipment($f, 3);

    $this->invoices->whenDispatched($shipment->id);

    expect(Invoice::query()->count())->toBe(0);
});
