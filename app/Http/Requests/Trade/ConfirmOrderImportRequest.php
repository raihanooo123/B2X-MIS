<?php

namespace App\Http\Requests\Trade;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/order-imports/{id}/confirm (05.1 §14.2): the preview
 * version, the selected rows, the rows whose suggested quantity was
 * accepted, and the basket version the preview was made against.
 */
class ConfirmOrderImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:0'],
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*' => ['integer', 'min:1'],
            'accept' => ['sometimes', 'array', 'max:5000'],
            'accept.*' => ['integer', 'min:1'],
            'cart_version' => ['required', 'string', 'size:64'],
        ];
    }

    /** @return list<int> */
    public function rowNos(): array
    {
        return array_values(array_map('intval', (array) $this->validated('rows')));
    }

    /** @return list<int> */
    public function accepted(): array
    {
        return array_values(array_map('intval', (array) ($this->validated('accept') ?? [])));
    }
}
