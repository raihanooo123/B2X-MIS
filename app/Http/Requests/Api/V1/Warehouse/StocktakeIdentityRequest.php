<?php

namespace App\Http\Requests\Api\V1\Warehouse;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Names one counted identity in a stocktake: the SKU by public id and,
 * for batch-tracked stock, the batch code — `(stocktake, sku, batch)` is
 * the line's identity (`stocktake_lines_identity_uq`).
 */
class StocktakeIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sku_id' => ['required', 'string', 'ulid'],
            'batch_code' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    public function skuPublicId(): string
    {
        return (string) $this->validated('sku_id');
    }

    public function batchCode(): ?string
    {
        $code = $this->validated('batch_code');

        return $code === null || trim((string) $code) === '' ? null : trim((string) $code);
    }
}
