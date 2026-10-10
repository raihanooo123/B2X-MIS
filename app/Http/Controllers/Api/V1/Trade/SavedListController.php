<?php

namespace App\Http\Controllers\Api\V1\Trade;

use App\Domain\Ordering\BulkEntry\BulkEntryImports;
use App\Domain\Ordering\BulkEntry\SavedLists;
use App\Domain\Ordering\TradeOrders;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trade\SavedListRequest;
use App\Http\Support\KeysetCursor;
use App\Http\Support\OrderTools;
use App\Http\Support\TradeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * 05.1 §14.2 — saved lists (GET/POST /api/v1/saved-lists, PATCH/DELETE
 * /api/v1/saved-lists/{id} with version, POST .../{id}/preview) and
 * reorder (POST /api/v1/orders/{id}/reorder-preview). Every company member
 * reads lists; buying roles create, edit, delete and use them. A preview
 * never adds to the basket — it opens the import reconciliation.
 */
class SavedListController extends Controller
{
    public function __construct(
        private readonly SavedLists $lists,
        private readonly BulkEntryImports $imports,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'sort' => ['sometimes', 'nullable', Rule::in(array_keys(SavedLists::SORTS))],
            'q' => ['sometimes', 'nullable', 'string', 'max:80'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:4096'],
        ]);
        [$user, $company] = TradeContext::resolve($request);
        Gate::authorize('viewTradeOrders', $company);
        $filters = ['sort' => (string) ($request->query('sort') ?: 'name'), 'q' => is_string($request->query('q')) && $request->query('q') !== '' ? (string) $request->query('q') : null];
        $after = KeysetCursor::decode(is_string($request->query('cursor')) ? $request->query('cursor') : null, 'api.saved-lists', $user->id, $company->id, $filters);
        $page = $this->lists->page($company->id, $filters['sort'], $filters['q'], $after);

        return response()->json([
            'data' => $page['rows'],
            'next_cursor' => $page['next'] === null ? null : KeysetCursor::encode('api.saved-lists', $user->id, $company->id, $filters, $page['next']),
            'has_more' => $page['next'] !== null,
        ]);
    }

    public function store(SavedListRequest $request): JsonResponse
    {
        [$user, $company] = TradeContext::resolve($request);
        Gate::authorize('useOrderTools', $company);
        $cart = $request->boolean('from_cart') ? OrderTools::cart($request, $company) : null;

        $list = $this->lists->create($company, $user, (string) $request->validated('name'), $cart);

        return response()->json(['data' => [...$this->lists->detail($list), 'url' => route('trade.saved-lists.show', $list->public_id)]], 201);
    }

    public function update(SavedListRequest $request, string $id): JsonResponse
    {
        [, $company] = TradeContext::resolve($request);
        Gate::authorize('useOrderTools', $company);
        $name = $request->validated('name');

        $list = $this->lists->update(OrderTools::savedList($company, $id), (int) $request->validated('version'), is_string($name) ? $name : null, $request->lines());

        return response()->json(['data' => $this->lists->detail($list)]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $request->validate(['version' => ['required', 'integer', 'min:0']]);
        [, $company] = TradeContext::resolve($request);
        Gate::authorize('useOrderTools', $company);

        $this->lists->delete(OrderTools::savedList($company, $id), (int) $request->input('version'));

        return response()->json(null, 204);
    }

    public function preview(Request $request, string $id): JsonResponse
    {
        [$user, $company] = TradeContext::resolve($request);
        Gate::authorize('useOrderTools', $company);

        $import = $this->lists->preview(OrderTools::savedList($company, $id), $company, $user);

        return response()->json(['data' => [...$this->imports->dto($import), 'url' => route('trade.order-tools.imports.show', $import->public_id)]], 201);
    }

    public function reorder(Request $request, string $id): JsonResponse
    {
        [$user, $company] = TradeContext::resolve($request);
        Gate::authorize('useOrderTools', $company);
        $order = TradeOrders::find($company, $id) ?? abort(404);

        $import = $this->lists->reorder($order, $company, $user);

        return response()->json(['data' => [...$this->imports->dto($import), 'url' => route('trade.order-tools.imports.show', $import->public_id)]], 201);
    }
}
