<?php

namespace App\Http\Requests\Trade;

use App\Domain\Ordering\TradeOrders;
use Illuminate\Validation\Rule;

/** GET /trade/orders and /api/v1/trade/orders (05.17 §2). */
class OrderHistoryRequest extends TradeListRequest
{
    protected function sorts(): array
    {
        return array_keys(TradeOrders::SORTS);
    }

    protected function filterRules(): array
    {
        return ['status' => ['sometimes', 'nullable', Rule::in(array_keys(TradeOrders::STATUS_GROUPS))]];
    }

    /** @return array{status: ?string, q: ?string, from: ?string, to: ?string, sort: string} */
    public function filters(): array
    {
        return ['status' => $this->text('status'), ...$this->common()];
    }
}
