<?php

namespace App\Http\Controllers\Api\V1\Trade;

use App\Domain\Billing\Statements;
use App\Domain\Billing\TradeDocuments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trade\GenerateStatementRequest;
use App\Http\Support\TradeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * 05.17 §4 — POST /api/v1/trade/statements: fix a statement for a UK date
 * range as at now, and queue its PDF. Owners and approvers. A new request
 * is a new statement with its own cutoff: statements are never edited.
 */
class StatementController extends Controller
{
    public function __construct(
        private readonly Statements $statements,
        private readonly TradeDocuments $documents,
    ) {}

    public function store(GenerateStatementRequest $request): JsonResponse
    {
        [$user, $company] = TradeContext::resolve($request);
        Gate::authorize('viewFinancialDocuments', $company);

        $statement = $this->statements->generate($company, $user, (string) $request->validated('from_on'), (string) $request->validated('to_on'));

        return response()->json([
            'data' => [
                'id' => $statement->public_id,
                'url' => route('trade.statements.show', $statement->public_id),
                'document' => $this->documents->documentState('statement', $statement->id, 'trade.statements.download', $statement->public_id),
            ],
        ], 201);
    }
}
