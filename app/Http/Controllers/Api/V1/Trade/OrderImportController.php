<?php

namespace App\Http\Controllers\Api\V1\Trade;

use App\Domain\Ordering\BulkEntry\BulkEntryImports;
use App\Domain\Ordering\BulkEntry\EntryParser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trade\ConfirmOrderImportRequest;
use App\Http\Requests\Trade\StoreOrderImportRequest;
use App\Http\Support\OrderTools;
use App\Http\Support\TradeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * 05.1 §14.2 — POST /api/v1/order-imports (paste or CSV → preview),
 * GET /api/v1/order-imports/{id} (bounded progress and the preview) and
 * POST /api/v1/order-imports/{id}/confirm (merge selected rows into the
 * basket, once). Buying roles of the acting company; the import is the
 * user's own. A GET never adds anything.
 */
class OrderImportController extends Controller
{
    public function __construct(
        private readonly BulkEntryImports $imports,
        private readonly EntryParser $parser = new EntryParser,
    ) {}

    public function store(StoreOrderImportRequest $request): JsonResponse
    {
        [$user, $company] = TradeContext::resolve($request);
        Gate::authorize('useOrderTools', $company);

        $file = $request->csvFile();
        if ($request->validated('source') === 'csv' && $file !== null) {
            $contents = (string) $file->get();
            $raw = $this->parser->csv($contents);
            $path = $file->storeAs('order-imports/'.$company->public_id, bin2hex(random_bytes(16)).'.csv', ['disk' => (string) config('documents.disk'), 'visibility' => 'private']);
            $import = $this->imports->stage($company, $user, 'csv', $raw, hash('sha256', $contents), is_string($path) ? $path : null);
        } else {
            $text = (string) $request->validated('text');
            $import = $this->imports->stage($company, $user, 'paste', $this->parser->paste($text), hash('sha256', $text));
        }

        return response()->json(['data' => [...$this->imports->dto($import), 'url' => route('trade.order-tools.imports.show', $import->public_id)]], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        [$user, $company] = TradeContext::resolve($request);
        Gate::authorize('useOrderTools', $company);

        return response()->json(['data' => $this->imports->dto(OrderTools::import($company, $user, $id))]);
    }

    public function confirm(ConfirmOrderImportRequest $request, string $id): JsonResponse
    {
        [$user, $company] = TradeContext::resolve($request);
        Gate::authorize('useOrderTools', $company);
        $import = OrderTools::import($company, $user, $id);

        $result = $this->imports->confirm(
            $import,
            OrderTools::cart($request, $company),
            (int) $request->validated('version'),
            $request->rowNos(),
            $request->accepted(),
            (string) $request->validated('cart_version'),
        );

        return response()->json(['data' => ['lines' => $result['lines'] ?? 0, 'row_nos' => $result['row_nos'] ?? [], 'cart_url' => url('/cart')]]);
    }
}
