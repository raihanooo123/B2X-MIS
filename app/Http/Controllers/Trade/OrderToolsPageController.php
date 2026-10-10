<?php

namespace App\Http\Controllers\Trade;

use App\Domain\Ordering\BulkEntry\BulkEntryImports;
use App\Domain\Ordering\BulkEntry\EntryParser;
use App\Domain\Ordering\BulkEntry\SavedLists;
use App\Http\Controllers\Controller;
use App\Http\Support\KeysetCursor;
use App\Http\Support\OrderTools;
use App\Http\Support\TradeContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * 05.1 §14.2 — the order-tool pages on the trade shell: paste/upload,
 * import reconciliation (with the rejection CSV and the template), saved
 * lists and a saved list. Reads only; changes go through /api/v1.
 */
class OrderToolsPageController extends Controller
{
    private const CSV_HEADERS = ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];

    public function __construct(
        private readonly BulkEntryImports $imports,
        private readonly SavedLists $lists,
    ) {}

    public function import(Request $request): Response
    {
        [, $company] = TradeContext::resolve($request);
        Gate::authorize('useOrderTools', $company);

        return Inertia::render('Trade/OrderTools/Import', [
            'limits' => ['max_rows' => EntryParser::MAX_ROWS, 'max_file_mb' => intdiv(BulkEntryImports::MAX_FILE_KB, 1024), 'queued_above' => BulkEntryImports::SYNC_LIMIT],
        ]);
    }

    public function show(Request $request, string $import): Response
    {
        [$user, $company] = TradeContext::resolve($request);
        Gate::authorize('useOrderTools', $company);
        $record = OrderTools::import($company, $user, $import);

        return Inertia::render('Trade/OrderTools/Reconcile', [
            'import' => $this->imports->dto($record),
            'cart_version' => fn () => $this->imports->cartVersion(OrderTools::cart($request, $company)),
        ]);
    }

    public function rejections(Request $request, string $import): SymfonyResponse
    {
        [$user, $company] = TradeContext::resolve($request);
        Gate::authorize('useOrderTools', $company);
        $record = OrderTools::import($company, $user, $import);

        return response($this->imports->rejectionsCsv($record), 200, [...self::CSV_HEADERS, 'Content-Disposition' => 'attachment; filename="import-problems.csv"']);
    }

    public function template(): SymfonyResponse
    {
        return response("sku_code,quantity,pack_code\nABC-123,2,\nABC-456,1,CASE12\n", 200, [...self::CSV_HEADERS, 'Content-Disposition' => 'attachment; filename="order-template.csv"']);
    }

    public function lists(Request $request): Response
    {
        $request->validate([
            'sort' => ['sometimes', 'nullable', Rule::in(array_keys(SavedLists::SORTS))],
            'q' => ['sometimes', 'nullable', 'string', 'max:80'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:4096'],
        ]);
        [$user, $company] = TradeContext::resolve($request);
        Gate::authorize('viewTradeOrders', $company);
        $filters = ['sort' => (string) ($request->query('sort') ?: 'name'), 'q' => is_string($request->query('q')) && $request->query('q') !== '' ? (string) $request->query('q') : null];
        $after = KeysetCursor::decode(is_string($request->query('cursor')) ? $request->query('cursor') : null, 'trade.saved-lists', $user->id, $company->id, $filters);
        $page = $this->lists->page($company->id, $filters['sort'], $filters['q'], $after);

        return Inertia::render('Trade/SavedLists/Index', [
            'filters' => $filters,
            'can_edit' => Gate::allows('useOrderTools', $company),
            'rows' => Inertia::merge($page['rows']),
            'next_cursor' => $page['next'] === null ? null : KeysetCursor::encode('trade.saved-lists', $user->id, $company->id, $filters, $page['next']),
        ]);
    }

    public function list(Request $request, string $list): Response
    {
        [, $company] = TradeContext::resolve($request);
        Gate::authorize('viewTradeOrders', $company);

        return Inertia::render('Trade/SavedLists/Show', [
            'list' => $this->lists->detail(OrderTools::savedList($company, $list)),
            'can_edit' => Gate::allows('useOrderTools', $company),
        ]);
    }
}
