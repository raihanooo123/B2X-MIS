<?php

namespace App\Http\Requests\Web\Collections;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 05.6 §7A.6 step 2: staff confirm "£X.XX received in cash". The amount on
 * the screen is sent back, so a total that moved since the page loaded is
 * refused rather than recorded.
 */
class RecordCashRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount_minor' => ['required', 'integer', 'min:1'],
            'received' => ['accepted'],
        ];
    }

    public function amountMinor(): int
    {
        return (int) $this->validated('amount_minor');
    }
}
