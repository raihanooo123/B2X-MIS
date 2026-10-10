<?php

namespace App\Http\Controllers\Trade;

use App\Domain\Billing\TradeDocuments;
use App\Domain\Documents\DocumentRenders;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trade\CreditNoteListRequest;
use App\Http\Support\DocumentDownload;
use App\Http\Support\KeysetCursor;
use App\Http\Support\TradeContext;
use App\Models\CreditNote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** 05.17 §4 — the acting company's credit notes and their PDFs, for owners and approvers. */
class CreditNotesPageController extends Controller
{
    private const LIST = 'trade.credit-notes';

    public function __construct(
        private readonly TradeDocuments $documents,
        private readonly DocumentRenders $renders,
    ) {}

    public function index(CreditNoteListRequest $request): Response
    {
        [$user, $company] = TradeContext::resolve($request);
        Gate::authorize('viewFinancialDocuments', $company);

        $filters = $request->filters();
        $after = KeysetCursor::decode($request->cursor(), self::LIST, $user->id, $company->id, $filters);
        $page = $this->documents->creditNotes($company->id, $filters, $after);

        return Inertia::render('Trade/CreditNotes/Index', [
            'company' => TradeContext::companyProps($company),
            'filters' => $filters,
            'rows' => Inertia::merge($page['rows']),
            'next_cursor' => $page['next'] === null ? null : KeysetCursor::encode(self::LIST, $user->id, $company->id, $filters, $page['next']),
        ]);
    }

    public function show(Request $request, string $creditNote): Response
    {
        return Inertia::render('Trade/CreditNotes/Show', ['credit_note' => $this->documents->creditNoteDetail($this->resolve($request, $creditNote))]);
    }

    public function download(Request $request, string $creditNote): SymfonyResponse
    {
        $record = $this->resolve($request, $creditNote);

        return DocumentDownload::respond($this->renders->readyAttachment('credit_note', $record->id), route('trade.credit-notes.show', $record->public_id));
    }

    private function resolve(Request $request, string $publicId): CreditNote
    {
        [, $company] = TradeContext::resolve($request);
        Gate::authorize('viewFinancialDocuments', $company);

        return CreditNote::query()->where('public_id', $publicId)->where('company_id', $company->id)->firstOrFail();
    }
}
