<?php

namespace App\Http\Requests\Trade;

use App\Domain\Billing\TradeDocuments;
use Illuminate\Validation\Rule;

/** GET /trade/invoices and /api/v1/trade/invoices (05.17 §2). */
class InvoiceListRequest extends TradeListRequest
{
    protected function sorts(): array
    {
        return array_keys(TradeDocuments::SORTS);
    }

    protected function filterRules(): array
    {
        return ['status' => ['sometimes', 'nullable', Rule::in([...array_keys(TradeDocuments::INVOICE_STATUS_GROUPS), 'overdue'])]];
    }

    /** @return array{status: ?string, q: ?string, from: ?string, to: ?string, sort: string} */
    public function filters(): array
    {
        return ['status' => $this->text('status'), ...$this->common()];
    }
}
