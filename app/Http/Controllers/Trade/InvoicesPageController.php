<?php

namespace App\Http\Controllers\Trade;

use App\Domain\Billing\TradeDocuments;
use App\Domain\Documents\DocumentRenders;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trade\InvoiceListRequest;
use App\Http\Support\DocumentDownload;
use App\Http\Support\KeysetCursor;
use App\Http\Support\TradeContext;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * 05.17 §4 — the acting company's invoices, an invoice and its archived
 * PDF, for owners and approvers (CompanyPolicy::viewFinancialDocuments),
 * re-checked on every request including the download.
 */
class InvoicesPageController extends Controller
{
    private const LIST = 'trade.invoices';

    public function __construct(
        private readonly TradeDocuments $documents,
        private readonly DocumentRenders $renders,
    ) {}

    public function index(InvoiceListRequest $request): Response
    {
        [$user, $company] = TradeContext::resolve($request);
        Gate::authorize('viewFinancialDocuments', $company);

        $filters = $request->filters();
        $after = KeysetCursor::decode($request->cursor(), self::LIST, $user->id, $company->id, $filters);
        $page = $this->documents->invoices($company->id, $filters, $after);

        return Inertia::render('Trade/Invoices/Index', [
            'company' => TradeContext::companyProps($company),
            'filters' => $filters,
            'summary' => fn () => $this->documents->invoiceSummary($company),
            'rows' => Inertia::merge($page['rows']),
            'next_cursor' => $page['next'] === null ? null : KeysetCursor::encode(self::LIST, $user->id, $company->id, $filters, $page['next']),
        ]);
    }

    public function show(Request $request, string $invoice): Response
    {
        $record = $this->resolve($request, $invoice);

        return Inertia::render('Trade/Invoices/Show', ['invoice' => $this->documents->invoiceDetail($record)]);
    }

    public function download(Request $request, string $invoice): SymfonyResponse
    {
        $record = $this->resolve($request, $invoice);

        return DocumentDownload::respond($this->renders->readyAttachment('invoice', $record->id), route('trade.invoices.show', $record->public_id));
    }

    private function resolve(Request $request, string $publicId): Invoice
    {
        [, $company] = TradeContext::resolve($request);
        Gate::authorize('viewFinancialDocuments', $company);

        return Invoice::query()->with('order:id,public_id,order_number')->where('public_id', $publicId)->where('company_id', $company->id)->firstOrFail();
    }
}
