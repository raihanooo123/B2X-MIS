<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Doc 06 §9.4 — GET /api/v1/stock/availability?sku_ids=01J8A,01J8B.
 * `sku_ids` arrives as one comma-separated query string, not a PHP
 * array param — exploded and re-merged here so it can be validated the
 * same way as bulk-resolve's array body param.
 */
class StockAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $raw = (string) $this->query('sku_ids', '');

        $ids = array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            fn (string $id): bool => $id !== '',
        ));

        $this->merge(['sku_ids' => $ids]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sku_ids' => ['required', 'array', 'min:1'],
            'sku_ids.*' => ['required', 'string', 'ulid'],
        ];
    }

    /**
     * @return list<string>
     */
    public function skuPublicIds(): array
    {
        return $this->input('sku_ids');
    }
}
