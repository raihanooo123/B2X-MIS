<?php

namespace App\Http\Requests\Trade;

use App\Domain\Billing\Documents\CreditNoteDocumentBuilder;
use App\Domain\Billing\TradeDocuments;
use Illuminate\Validation\Rule;

/** GET /trade/credit-notes (05.17 §2). */
class CreditNoteListRequest extends TradeListRequest
{
    protected function sorts(): array
    {
        return array_keys(TradeDocuments::SORTS);
    }

    protected function filterRules(): array
    {
        return ['reason' => ['sometimes', 'nullable', Rule::in(array_keys(CreditNoteDocumentBuilder::REASONS))]];
    }

    /** @return array{reason: ?string, q: ?string, from: ?string, to: ?string, sort: string} */
    public function filters(): array
    {
        return ['reason' => $this->text('reason'), ...$this->common()];
    }
}
