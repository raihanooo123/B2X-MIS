<?php

namespace App\Http\Requests\Trade;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/trade/statements (05.17 §2): a UK calendar range. The
 * 12-month limit, future dates and order are checked by Statements, which
 * owns the rule.
 */
class GenerateStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from_on' => ['required', 'date_format:Y-m-d'],
            'to_on' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
