<?php

use App\Domain\Billing\InvoicePdfArchiver;
use App\Domain\Billing\InvoiceService;
use App\Domain\Documents\ArchivedDocument;
use App\Domain\Documents\BrowsershotPdfRenderer;
use App\Domain\Documents\DocumentRenders;
use App\Domain\Documents\PdfBytes;
use App\Domain\Documents\PdfDocument;
use App\Domain\Documents\PdfRenderer;
use App\Domain\Documents\PdfRenderFailed;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\DocumentRender;
use App\Models\NumberSequence;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderLine;
use App\Models\SystemConfiguration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MinimalPdf;

/**
 * 05.17 §3, §5 — archived documents: the payload fixed at issue and never
 * changed, real PDF bytes or a classified failure (never a null success),
 * retry with the same payload and row, no second number, the missing-
 * snapshot blocker, the database immutability guard, and safe templates.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(config('documents.disk'));
    NumberSequence::factory()->forSeries('invoice_number', 'INV-')->create(['next_value' => 1, 'padding' => 6]);
    NumberSequence::factory()->forSeries('receipt_number', 'RCP-')->create(['next_value' => 1, 'padding' => 6]);
    foreach (['seller.legal_name' => 'Render Wholesale Ltd', 'seller.address' => "1 Print Street\nLondon", 'seller.company_number' => '07654321', 'seller.vat_number' => 'GB999999973'] as $key => $value) {
        SystemConfiguration::factory()->create(['config_key' => $key, 'value_type' => 'text', 'value_int' => null, 'value_text' => $value]);
    }
});

/** A trade order whose totals agree with its one line, ready to invoice. */
function drOrder(array $overrides = []): Order
{
    $order = Order::factory()->create($overrides + [
        'payment_method' => 'bacs',
        'subtotal_net_minor' => 10_000,
        'shipping_net_minor' => 0,
        'shipping_tax_minor' => 0,
        'tax_minor' => 2_000,
        'total_gross_minor' => 12_000,
    ]);
    OrderLine::factory()->create([
        'order_id' => $order->id, 'line_no' => 1, 'unit_price_net_e4' => 1_000_000,
        'line_net_minor' => 10_000, 'tax_rate_bp' => 2000, 'line_tax_minor' => 2_000, 'line_gross_minor' => 12_000,
    ]);
    OrderAddress::factory()->billing()->create(['order_id' => $order->id]);

    return $order;
}

function drRenders(PdfRenderer $renderer): DocumentRenders
{
    return new DocumentRenders($renderer);
}

it('captures the invoice payload at issue and prints a reprint identically after the company and seller change', function () {
    $order = drOrder();
    $invoice = (new InvoiceService)->issueForOrder($order->id);
    $render = DocumentRender::query()->where('document_type', 'invoice')->where('source_id', $invoice->id)->sole();

    // Later edits to the customer and the seller never reach the archived payload.
    DB::table('companies')->where('id', $order->company_id)->update(['name' => 'Renamed Ltd']);
    SystemConfiguration::query()->where('config_key', 'seller.legal_name')->update(['value_text' => 'Someone Else plc']);

    $archiver = new InvoicePdfArchiver($renderer = new MinimalPdf);
    $archiver->archive($invoice->id);
    $fresh = $render->fresh();

    expect($fresh->status)->toBe('ready')
        ->and($fresh->version)->toBe(1)
        ->and($fresh->payload['number'])->toBe($invoice->invoice_number)
        ->and($fresh->payload['seller']['legal_name'])->toBe('Render Wholesale Ltd')
        ->and($fresh->payload['customer']['name'])->not->toBe('Renamed Ltd')
        ->and($fresh->payload_sha256)->toBe(DocumentRenders::hash($fresh->payload))
        ->and($renderer->templates)->toBe(['invoice']);

    $html = BrowsershotPdfRenderer::html(new ArchivedDocument('invoice', $fresh->payload));
    expect($html)->toContain('Render Wholesale Ltd')->not->toContain('Someone Else plc')->not->toContain('Renamed Ltd');
});

it('archives real PDF bytes once, privately, named by the document number', function () {
    $invoice = (new InvoiceService)->issueForOrder(drOrder()->id);
    $render = drRenders(new MinimalPdf)->latest('invoice', $invoice->id);

    $renders = drRenders($renderer = new MinimalPdf);
    $renders->render($render->id);
    $renders->render($render->id);

    $attachment = Attachment::query()->where('attachable_type', 'invoice')->where('attachable_id', $invoice->id)->sole();
    $bytes = Storage::disk(config('documents.disk'))->get($attachment->path);
    expect($renderer->calls)->toBe(1)
        ->and(str_starts_with((string) $bytes, '%PDF-'))->toBeTrue()
        ->and(PdfBytes::pageCount((string) $bytes))->toBe(1)
        ->and($attachment->original_name)->toBe("{$invoice->invoice_number}.pdf")
        ->and($attachment->path)->not->toContain('public')
        ->and($render->fresh()->attachment_id)->toBe($attachment->id);
});

it('never treats the null renderer or non-PDF bytes as success', function (PdfRenderer $renderer, string $reason) {
    $invoice = (new InvoiceService)->issueForOrder(drOrder()->id);
    $render = drRenders($renderer)->latest('invoice', $invoice->id);

    $result = drRenders($renderer)->render($render->id);

    expect($result->status)->toBe('failed')
        ->and($result->error_code)->toBe($reason)
        ->and($result->attachment_id)->toBeNull()
        ->and(Attachment::query()->where('attachable_type', 'invoice')->count())->toBe(0);
})->with([
    'null renderer' => [fn () => new class implements PdfRenderer
    {
        public function render(PdfDocument $document): ?string
        {
            return null;
        }
    }, PdfRenderFailed::RENDERER_UNAVAILABLE],
    'not a PDF' => [fn () => new class implements PdfRenderer
    {
        public function render(PdfDocument $document): ?string
        {
            return '<html>not a pdf</html>';
        }
    }, PdfRenderFailed::NOT_A_PDF],
    'empty' => [fn () => new class implements PdfRenderer
    {
        public function render(PdfDocument $document): ?string
        {
            return '';
        }
    }, PdfRenderFailed::NOT_A_PDF],
]);

it('retries a failed render with the same row, payload and number, and recovers a render left mid-way by a crash', function () {
    $invoice = (new InvoiceService)->issueForOrder(drOrder()->id);
    $renders = drRenders(new MinimalPdf);
    $render = $renders->latest('invoice', $invoice->id);
    // The sync queue already ran it with the suite's null renderer.
    expect($render->status)->toBe('failed');
    $payload = $render->payload;

    $renders->retry($render);
    expect($render->fresh()->status)->toBe('pending');

    // A worker that died after taking the render leaves it `rendering`; the queue's retry finishes it.
    DB::table('document_renders')->where('id', $render->id)->update(['status' => 'rendering']);
    $done = $renders->render($render->id);

    expect($done->status)->toBe('ready')
        ->and($done->id)->toBe($render->id)
        ->and($done->payload)->toBe($payload)
        ->and(DocumentRender::query()->where('source_id', $invoice->id)->count())->toBe(1)
        ->and(DB::table('invoices')->count())->toBe(1);
});

it('captures a source once however often the issue hook runs', function () {
    $invoice = (new InvoiceService)->issueForOrder(drOrder()->id);

    InvoicePdfArchiver::capture($invoice->id);
    InvoicePdfArchiver::capture($invoice->id);

    expect(DocumentRender::query()->where('document_type', 'invoice')->where('source_id', $invoice->id)->count())->toBe(1);
});

it('blocks an invoice issued before archiving instead of rebuilding it from today\'s details', function () {
    $invoice = (new InvoiceService)->issueForOrder(drOrder()->id);
    DB::table('document_renders')->where('source_id', $invoice->id)->where('status', '<>', 'ready')->delete();

    expect((new InvoicePdfArchiver(new MinimalPdf))->archive($invoice->id))->toBeNull()
        ->and(DocumentRender::query()->where('source_id', $invoice->id)->count())->toBe(0);
});

it('refuses, in the database, any change to a payload or a ready archive', function () {
    $invoice = (new InvoiceService)->issueForOrder(drOrder()->id);
    $renders = drRenders(new MinimalPdf);
    $render = $renders->latest('invoice', $invoice->id);

    foreach ([
        ['payload' => json_encode(['number' => 'FORGED'])],
        ['payload_sha256' => str_repeat('0', 64)],
        ['source_id' => $render->source_id + 1],
        ['template_version' => 'other'],
    ] as $change) {
        expect(fn () => DB::transaction(fn () => DB::table('document_renders')->where('id', $render->id)->update($change)))
            ->toThrow(QueryException::class, 'immutable');
    }

    $renders->retry($render);
    $renders->render($render->id);
    expect(fn () => DB::transaction(fn () => DB::table('document_renders')->where('id', $render->id)->update(['status' => 'failed'])))
        ->toThrow(QueryException::class, 'immutable');
    expect(fn () => DB::transaction(fn () => DB::table('document_renders')->where('id', $render->id)->delete()))
        ->toThrow(QueryException::class, 'kept');
});

it('captures a credit note when issued, whichever service issues it', function () {
    $company = Company::factory()->create();
    $order = drOrder(['company_id' => $company->id]);
    // The credit_note_number series is seeded by its migration.
    NumberSequence::query()->updateOrCreate(['key_name' => 'credit_note_number'], ['prefix' => 'CN-', 'next_value' => 1, 'padding' => 6]);

    $note = CreditNote::query()->create([
        'credit_note_number' => 'CN-000001', 'company_id' => $company->id, 'order_id' => $order->id,
        'reason' => 'goodwill', 'subtotal_net_minor' => 1_000, 'tax_minor' => 200, 'total_gross_minor' => 1_200, 'issued_at' => now(),
    ]);

    $render = DocumentRender::query()->where('document_type', 'credit_note')->where('source_id', $note->id)->sole();
    expect($render->company_id)->toBe($company->id)
        ->and($render->payload['number'])->toBe('CN-000001')
        ->and($render->payload['totals']['total_gross_minor'])->toBe(1_200)
        ->and($render->payload['totals']['total_gross'])->toBe('£12.00')
        ->and($render->payload['reason_label'])->toBe('Goodwill');
});

it('keeps costs, staff notes and provider ids out of every payload', function () {
    $invoice = (new InvoiceService)->issueForOrder(drOrder()->id);
    $json = json_encode(drRenders(new MinimalPdf)->latest('invoice', $invoice->id)->payload);

    foreach (['cost', 'xero', 'review_note', 'internal', 'path', 'disk'] as $forbidden) {
        expect($json)->not->toContain($forbidden);
    }
});

it('escapes customer text in the print HTML and lets Chromium fetch nothing', function () {
    $invoice = (new InvoiceService)->issueForOrder(drOrder(['customer_reference' => '<img src=x onerror=alert(1)><script>steal()</script>'])->id);
    $payload = drRenders(new MinimalPdf)->latest('invoice', $invoice->id)->payload;
    $payload['lines'][0]['description'] = '"><iframe src="https://evil.example/"></iframe>';

    $html = BrowsershotPdfRenderer::html(new ArchivedDocument('invoice', $payload));

    expect($html)->not->toContain('<script>steal()')
        ->not->toContain('<img src=x')
        ->not->toContain('<iframe')
        ->toContain('&lt;script&gt;steal()')
        ->toContain("default-src 'none'")
        ->not->toMatch('/(src|href)=["\']?(https?:|file:|\/\/)/i');
});

it('refuses a template it does not know', function () {
    expect(fn () => BrowsershotPdfRenderer::html(new ArchivedDocument('../../etc/passwd', [])))
        ->toThrow(PdfRenderFailed::class, PdfRenderFailed::UNKNOWN_TEMPLATE);
});

it('prints a real A4 PDF with Chromium, long tables over many pages within 07 P25', function () {
    $lines = [];
    foreach (range(1, 1500) as $i) {
        $lines[] = ['line_no' => $i, 'sku_code' => "SKU-{$i}", 'description' => "Item {$i}", 'pack_label' => 'Case', 'pack_qty' => 1, 'base_qty' => 12,
            'unit_price_net' => '£1.0000', 'vat_rate' => '20%', 'line_net' => '£12.00', 'line_vat' => '£2.40'];
    }
    $document = new ArchivedDocument('invoice', [
        'kind' => 'vat_invoice', 'title' => 'VAT invoice', 'number' => 'INV-PERF', 'issued_on' => '2026-10-27', 'issued_on_display' => '27 Oct 2026', 'tax_point' => '2026-10-27',
        'order_number' => 'SO-1', 'customer_reference' => null, 'seller' => ['legal_name' => 'Seller', 'address_lines' => []],
        'customer' => ['name' => 'Buyer', 'address_lines' => [str_repeat('A long address line that must wrap ', 10)]], 'lines' => $lines, 'carriage' => null,
        'vat_summary' => [['rate' => '20%', 'net' => '£18,000.00', 'vat' => '£3,600.00']], 'totals' => ['discount_net_minor' => 0, 'total_gross' => '£21,600.00', 'paid_minor' => 0],
    ]);

    $started = microtime(true);
    $pdf = (new BrowsershotPdfRenderer)->render($document);
    $seconds = microtime(true) - $started;

    PdfBytes::validate((string) $pdf);
    expect(PdfBytes::pageCount((string) $pdf))->toBeGreaterThan(50)
        ->and($seconds)->toBeLessThan(30.0);
})->skip(fn () => getenv('RUN_PDF_RENDERER') !== '1', 'Real Chromium render: run with RUN_PDF_RENDERER=1.');
