<?php

use App\Domain\Billing\Documents\InvoiceDocumentBuilder;
use App\Domain\Billing\Exceptions\InvoiceTotalsMismatchException;
use App\Domain\Billing\Exceptions\SellerVatNumberMissingException;
use App\Domain\Billing\InvoicePdfArchiver;
use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\PaymentAllocationService;
use App\Domain\Documents\PdfDocument;
use App\Domain\Documents\PdfRenderer;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\CreditHold;
use App\Models\Invoice;
use App\Models\NumberSequence;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\SystemConfiguration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    NumberSequence::factory()->forSeries('invoice_number', 'INV-')->create(['next_value' => 1, 'padding' => 6]);
    NumberSequence::factory()->forSeries('receipt_number', 'RCP-')->create(['next_value' => 1, 'padding' => 6]);
    invoiceSeller();
});

function invoiceSeller(bool $withVatNumber = true): void
{
    $values = [
        'seller.legal_name' => 'Test Wholesale Ltd',
        'seller.address' => "1 Test Street\nLondon\nE1 6AN",
        'seller.company_number' => '01234567',
    ];
    if ($withVatNumber) {
        $values['seller.vat_number'] = 'GB123456789';
    }
    foreach ($values as $key => $value) {
        SystemConfiguration::factory()->create(['config_key' => $key, 'value_type' => 'text', 'value_int' => null, 'value_text' => $value]);
    }
}

/**
 * An order whose totals agree with its lines, as checkout writes them.
 *
 * @param  list<array{int, int}>  $lines  [line_net_minor, tax_rate_bp]
 * @param  array<string, mixed>  $attributes
 */
function invoiceableOrder(array $attributes = [], array $lines = [[10000, 2000]], int $shippingNet = 0, int $shippingRateBp = 2000): Order
{
    $lineTax = fn (int $net, int $bp) => intdiv($net * $bp + 5000, 10000);
    $subtotal = array_sum(array_column($lines, 0));
    $shippingTax = $lineTax($shippingNet, $shippingRateBp);
    $tax = array_sum(array_map(fn ($l) => $lineTax($l[0], $l[1]), $lines)) + $shippingTax;

    $order = Order::factory()->create([
        'payment_method' => 'bacs',
        'subtotal_net_minor' => $subtotal,
        'shipping_net_minor' => $shippingNet,
        'shipping_tax_minor' => $shippingTax,
        'shipping_tax_rate_bp' => $shippingNet > 0 ? $shippingRateBp : null,
        'tax_minor' => $tax,
        'total_gross_minor' => $subtotal + $shippingNet + $tax,
        ...$attributes,
    ]);

    foreach ($lines as $i => [$net, $bp]) {
        OrderLine::factory()->create([
            'order_id' => $order->id,
            'line_no' => $i + 1,
            'unit_price_net_e4' => $net * 100,
            'line_net_minor' => $net,
            'tax_rate_bp' => $bp,
            'line_tax_minor' => $lineTax($net, $bp),
            'line_gross_minor' => $net + $lineTax($net, $bp),
        ]);
    }
    OrderAddress::factory()->billing()->create(['order_id' => $order->id]);

    return $order->fresh();
}

function nextNumber(string $series): int
{
    return (int) NumberSequence::query()->whereKey($series)->value('next_value');
}

it('issues a gapless VAT invoice for a trade BACS order, due on issue', function () {
    $first = (new InvoiceService)->issueForOrder(invoiceableOrder()->id);
    $second = (new InvoiceService)->issueForOrder(invoiceableOrder()->id);

    expect($first->invoice_number)->toBe('INV-000001')
        ->and($second->invoice_number)->toBe('INV-000002')
        ->and($first->payment_terms)->toBe('prepay')
        ->and($first->due_at->equalTo($first->issued_at))->toBeTrue()
        ->and($first->shipment_id)->toBeNull()
        ->and($first->status)->toBe('issued')
        ->and($first->total_gross_minor)->toBe(12000);
});

it('returns the existing invoice on a repeat issue and consumes no number', function () {
    $order = invoiceableOrder();
    $service = new InvoiceService;

    $first = $service->issueForOrder($order->id);
    $again = $service->issueForOrder($order->id);

    expect($again->id)->toBe($first->id)
        ->and(Invoice::query()->count())->toBe(1)
        ->and(nextNumber('invoice_number'))->toBe(2);
});

it('snapshots on-account terms and converts the credit hold to credit used', function () {
    $company = Company::factory()->create(['payment_terms' => 'net30', 'credit_limit_minor' => 1_000_000, 'credit_held_minor' => 12000, 'credit_used_minor' => 500]);
    $order = invoiceableOrder(['company_id' => $company->id, 'payment_method' => 'on_account', 'payment_status' => 'on_account']);
    $hold = CreditHold::factory()->create(['company_id' => $company->id, 'order_id' => $order->id, 'amount_minor' => 12000]);

    $invoice = (new InvoiceService)->issueForOrder($order->id);
    $company->update(['payment_terms' => 'net7']);

    expect($invoice->fresh()->payment_terms)->toBe('net30')
        ->and($invoice->due_at->equalTo($invoice->issued_at->copy()->addDays(30)))->toBeTrue()
        ->and($hold->fresh()->status)->toBe('invoiced')
        ->and($hold->fresh()->invoice_id)->toBe($invoice->id)
        ->and($company->fresh()->credit_held_minor)->toBe(0)
        ->and($company->fresh()->credit_used_minor)->toBe(12500);
});

it('touches no credit for a prepaid trade invoice', function () {
    $company = Company::factory()->create(['credit_held_minor' => 0, 'credit_used_minor' => 0]);

    (new InvoiceService)->issueForOrder(invoiceableOrder(['company_id' => $company->id])->id);

    expect($company->fresh()->credit_used_minor)->toBe(0);
});

it('gives a public customer a receipt from its own series, with no terms or due date', function () {
    $order = invoiceableOrder(['company_id' => null, 'payment_method' => 'card']);

    $receipt = (new InvoiceService)->issueForOrder($order->id);

    expect($receipt->isReceipt())->toBeTrue()
        ->and($receipt->invoice_number)->toBe('RCP-000001')
        ->and($receipt->payment_terms)->toBeNull()
        ->and($receipt->due_at)->toBeNull()
        ->and(nextNumber('invoice_number'))->toBe(1);
});

it('refuses a VAT invoice without the seller VAT number, consuming no number, but still issues receipts', function () {
    SystemConfiguration::query()->where('config_key', 'seller.vat_number')->delete();

    expect(fn () => (new InvoiceService)->issueForOrder(invoiceableOrder()->id))
        ->toThrow(SellerVatNumberMissingException::class);
    expect(nextNumber('invoice_number'))->toBe(1)
        ->and(Invoice::query()->count())->toBe(0);

    $receipt = (new InvoiceService)->issueForOrder(invoiceableOrder(['company_id' => null, 'payment_method' => 'card'])->id);
    expect($receipt->invoice_number)->toBe('RCP-000001');
});

it('refuses to invoice a cancelled order', function () {
    $order = invoiceableOrder(['status' => 'cancelled']);

    expect(fn () => (new InvoiceService)->issueForOrder($order->id))->toThrow(LogicException::class);
    expect(nextNumber('invoice_number'))->toBe(1);
});

it('rejects a half-receipt row at the database (invoices_kind_chk)', function () {
    $order = invoiceableOrder();

    expect(fn () => DB::table('invoices')->insert([
        'public_id' => 'x1', 'invoice_number' => 'INV-X', 'company_id' => $order->company_id,
        'order_id' => $order->id, 'payment_terms' => null, 'due_at' => null,
    ]))->toThrow(QueryException::class);
});

it('applies a public card payment to its receipt when issued (02 §21.2)', function () {
    $order = invoiceableOrder(['company_id' => null, 'payment_method' => 'card', 'payment_status' => 'paid']);
    Payment::factory()->create(['company_id' => null, 'order_id' => $order->id, 'amount_minor' => $order->total_gross_minor]);

    $receipt = (new InvoiceService)->issueForOrder($order->id)->fresh();

    expect($receipt->paid_minor)->toBe($order->total_gross_minor)
        ->and($receipt->status)->toBe('paid');
});

it('releases credit used when an on-account invoice is paid', function () {
    $company = Company::factory()->create(['payment_terms' => 'net30', 'credit_used_minor' => 0, 'credit_held_minor' => 0]);
    $order = invoiceableOrder(['company_id' => $company->id, 'payment_method' => 'on_account', 'payment_status' => 'on_account']);
    $invoice = (new InvoiceService)->issueForOrder($order->id);
    expect($company->fresh()->credit_used_minor)->toBe(12000);

    $payment = Payment::factory()->bacs()->create(['company_id' => $company->id, 'order_id' => $order->id, 'amount_minor' => 5000, 'status' => 'captured']);
    (new PaymentAllocationService)->allocatePayment($payment->id);

    expect($invoice->fresh()->paid_minor)->toBe(5000)
        ->and($invoice->fresh()->status)->toBe('part_paid')
        ->and($company->fresh()->credit_used_minor)->toBe(7000);
});

it('does not throw from the after-commit trigger when issuing fails, and leaves the order standing', function () {
    SystemConfiguration::query()->where('config_key', 'seller.vat_number')->delete();
    $order = invoiceableOrder();

    (new InvoiceService)->whenPlaced($order->id);

    expect(Invoice::query()->count())->toBe(0)
        ->and($order->fresh()->status)->toBe('confirmed');
});

it('invoices a trade BACS order at placement but not an on-account one', function () {
    $bacs = invoiceableOrder();
    $onAccount = invoiceableOrder(['payment_method' => 'on_account', 'payment_status' => 'on_account']);

    (new InvoiceService)->whenPlaced($bacs->id);
    (new InvoiceService)->whenPlaced($onAccount->id);
    (new InvoiceService)->whenPaid($onAccount->id);

    expect(Invoice::query()->pluck('order_id')->all())->toBe([$bacs->id]);
});

it('builds the document with per-line VAT, carriage and a VAT summary by rate', function () {
    $order = invoiceableOrder(lines: [[10000, 2000], [5000, 0], [2500, 2000]], shippingNet: 1500);
    $invoice = (new InvoiceService)->issueForOrder($order->id);

    $doc = (new InvoiceDocumentBuilder)->build($invoice)->toArray();

    expect($doc['kind'])->toBe('vat_invoice')
        ->and($doc['title'])->toBe('VAT invoice')
        ->and($doc['seller']['vat_number'])->toBe('GB123456789')
        ->and($doc['seller']['address_lines'])->toBe(['1 Test Street', 'London', 'E1 6AN'])
        ->and($doc['payment_terms'])->toBe('prepay')
        ->and($doc['due_on'])->toBe($invoice->issued_at->toDateString())
        ->and(array_column($doc['lines'], 'vat_rate'))->toBe(['20%', '0%', '20%'])
        ->and($doc['carriage']['net_minor'])->toBe(1500)
        ->and($doc['vat_summary'])->toHaveCount(2)
        ->and($doc['vat_summary'][0]['rate_bp'])->toBe(2000)
        ->and($doc['vat_summary'][0]['net_minor'])->toBe(14000)
        ->and($doc['vat_summary'][0]['vat_minor'])->toBe(2800)
        ->and($doc['vat_summary'][1]['net_minor'])->toBe(5000)
        ->and($doc['vat_summary'][1]['vat_minor'])->toBe(0)
        ->and($doc['totals']['total_gross_minor'])->toBe(21800)
        ->and($doc['totals']['total_gross'])->toBe('£218.00');
});

it('prints a receipt with no payment terms or due date', function () {
    $receipt = (new InvoiceService)->issueForOrder(invoiceableOrder(['company_id' => null, 'payment_method' => 'card'])->id);

    $doc = (new InvoiceDocumentBuilder)->build($receipt)->toArray();

    expect($doc['kind'])->toBe('receipt')
        ->and($doc['title'])->toBe('Receipt')
        ->and($doc['payment_terms'])->toBeNull()
        ->and($doc['due_on'])->toBeNull();
});

it('refuses to build a document whose lines disagree with its totals', function () {
    $invoice = (new InvoiceService)->issueForOrder(invoiceableOrder()->id);
    OrderLine::query()->where('order_id', $invoice->order_id)->update(['line_tax_minor' => 1999]);

    expect(fn () => (new InvoiceDocumentBuilder)->build($invoice->fresh()))->toThrow(InvoiceTotalsMismatchException::class);
});

it('formats VAT rates from basis points without floats', function () {
    expect(InvoiceDocumentBuilder::percent(2000))->toBe('20%')
        ->and(InvoiceDocumentBuilder::percent(500))->toBe('5%')
        ->and(InvoiceDocumentBuilder::percent(1750))->toBe('17.5%')
        ->and(InvoiceDocumentBuilder::percent(0))->toBe('0%');
});

it('archives the rendered PDF once, as a customer-visible invoice attachment', function () {
    Storage::fake(config('filesystems.default'));
    $renderer = new class implements PdfRenderer
    {
        public int $calls = 0;

        public function render(PdfDocument $document): ?string
        {
            $this->calls++;

            return '%PDF-1.7 '.$document->template();
        }
    };
    $invoice = (new InvoiceService)->issueForOrder(invoiceableOrder()->id);

    $archiver = new InvoicePdfArchiver($renderer);
    $archiver->archive($invoice->id);
    $archiver->archive($invoice->id);

    $attachment = Attachment::query()->sole();
    expect($renderer->calls)->toBe(1)
        ->and($attachment->attachable_type)->toBe('invoice')
        ->and($attachment->attachable_id)->toBe($invoice->id)
        ->and($attachment->is_customer_visible)->toBeTrue()
        ->and($attachment->original_name)->toBe('INV-000001.pdf')
        ->and($invoice->fresh()->archivedPdf?->id)->toBe($attachment->id);
    Storage::disk(config('filesystems.default'))->assertExists($attachment->path);
});

it('archives nothing while no PDF renderer is configured', function () {
    (new InvoiceService)->issueForOrder(invoiceableOrder()->id);

    expect(Attachment::query()->count())->toBe(0);
});

it('reports orders missing their invoice, and issues them only with --fix', function () {
    SystemConfiguration::query()->where('config_key', 'seller.vat_number')->delete();
    $order = invoiceableOrder();
    (new InvoiceService)->whenPlaced($order->id);
    SystemConfiguration::factory()->create(['config_key' => 'seller.vat_number', 'value_type' => 'text', 'value_int' => null, 'value_text' => 'GB123456789']);

    $this->artisan('billing:issue-missing-invoices')->assertFailed();
    expect(Invoice::query()->count())->toBe(0);

    $this->artisan('billing:issue-missing-invoices --fix')->assertSuccessful();
    expect(Invoice::query()->sole()->order_id)->toBe($order->id);
});
