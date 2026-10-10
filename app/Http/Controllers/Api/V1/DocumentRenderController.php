<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\TradeDocuments;
use App\Domain\Documents\DocumentRenders;
use App\Domain\Documents\PdfRenderFailed;
use App\Http\Controllers\Controller;
use App\Http\Exceptions\ApiException;
use App\Http\Requests\Trade\DocumentRenderRequest;
use App\Http\Support\TradeContext;
use App\Models\AccountStatement;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\DocumentRender;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * 05.17 §4 — preparing a PDF: POST /api/v1/document-renders (queue the
 * source's render), GET /api/v1/document-renders/{id} (poll), POST
 * /api/v1/document-renders/{id}/retry (a failed render, same payload).
 *
 * Every call inherits the source's policy, re-checked each time against
 * the acting company — a render of another company, or one the user can no
 * longer see after a membership change or company switch, is a 404/403.
 * Only the explicit POSTs queue; a GET never does. Failures are shown in
 * plain words, never the technical reason.
 */
class DocumentRenderController extends Controller
{
    private const DOWNLOAD_ROUTES = [
        'invoice' => 'trade.invoices.download',
        'credit_note' => 'trade.credit-notes.download',
        'statement' => 'trade.statements.download',
    ];

    public function __construct(private readonly DocumentRenders $renders) {}

    public function store(DocumentRenderRequest $request): JsonResponse
    {
        [, $company] = TradeContext::resolve($request);
        Gate::authorize('viewFinancialDocuments', $company);
        $type = (string) $request->validated('type');
        [$sourceId, $sourcePublicId] = $this->source($company, $type, (string) $request->validated('source'));

        $render = $this->renders->latest($type, $sourceId);
        if ($render === null) {
            return ApiException::envelope($request, 409, PdfRenderFailed::MISSING_SNAPSHOT, PdfRenderFailed::customerMessage(PdfRenderFailed::MISSING_SNAPSHOT));
        }

        $render = $this->renders->queue($render);

        return $this->respond($render, $sourcePublicId, $render->status === DocumentRender::READY ? 200 : 202);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        [$render, $sourcePublicId] = $this->authorised($request, $id);

        return $this->respond($render, $sourcePublicId);
    }

    public function retry(Request $request, string $id): JsonResponse
    {
        [$render, $sourcePublicId] = $this->authorised($request, $id);
        if ($render->status !== DocumentRender::FAILED) {
            return $this->respond($render, $sourcePublicId);
        }

        return $this->respond($this->renders->queue($render), $sourcePublicId, 202);
    }

    /** @return array{0: DocumentRender, 1: string} */
    private function authorised(Request $request, string $id): array
    {
        [, $company] = TradeContext::resolve($request);
        Gate::authorize('viewFinancialDocuments', $company);
        $render = DocumentRender::query()->where('public_id', $id)->where('company_id', $company->id)
            ->whereIn('document_type', array_keys(self::DOWNLOAD_ROUTES))->firstOrFail();

        // The source must still belong to the acting company (02 §31.2 reconciliation).
        $sourcePublicId = match ($render->document_type) {
            'invoice' => Invoice::query()->whereKey($render->source_id)->where('company_id', $company->id)->value('public_id'),
            'credit_note' => CreditNote::query()->whereKey($render->source_id)->where('company_id', $company->id)->value('public_id'),
            default => AccountStatement::query()->whereKey($render->source_id)->where('company_id', $company->id)->value('public_id'),
        };
        abort_if(! is_string($sourcePublicId), 404);

        return [$render, $sourcePublicId];
    }

    /** @return array{0: int, 1: string} the source's internal id and public id, in the acting company */
    private function source(Company $company, string $type, string $publicId): array
    {
        $record = match ($type) {
            'invoice' => Invoice::query()->where('public_id', $publicId)->where('company_id', $company->id)->first(['id', 'public_id']),
            'credit_note' => CreditNote::query()->where('public_id', $publicId)->where('company_id', $company->id)->first(['id', 'public_id']),
            default => AccountStatement::query()->where('public_id', $publicId)->where('company_id', $company->id)->first(['id', 'public_id']),
        };
        abort_if($record === null, 404);

        return [(int) $record->getKey(), (string) $record->getAttribute('public_id')];
    }

    private function respond(DocumentRender $render, string $sourcePublicId, int $status = 200): JsonResponse
    {
        $download = $render->status === DocumentRender::READY ? route(self::DOWNLOAD_ROUTES[$render->document_type], $sourcePublicId) : null;

        return response()->json(['data' => [
            'type' => $render->document_type,
            'source' => $sourcePublicId,
            ...TradeDocuments::renderDto($render, $download),
        ]], $status);
    }
}
