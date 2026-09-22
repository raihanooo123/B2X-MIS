<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Doc 06 §9.1 — POST /api/v1/pricing/bulk-resolve.
 *
 * `sku_ids` are ULID public_ids (06 §2: "auto-increment ids are never
 * exposed"), never resolved against skus.id directly.
 *
 * `base_qty` is not in the doc's own request example, which shows only
 * `sku_ids`/`include_breaks` — additive per §2's versioning rule ("new
 * fields... ship without a version bump"), defaulting to 1 so the
 * documented example works exactly as written.
 */
class BulkResolveRequest extends FormRequest
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
            'sku_ids' => ['required', 'array', 'min:1'],
            'sku_ids.*' => ['required', 'string', 'ulid'],
            'include_breaks' => ['sometimes', 'boolean'],
            'base_qty' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * @return list<string>
     */
    public function skuPublicIds(): array
    {
        return $this->input('sku_ids');
    }

    public function includeBreaks(): bool
    {
        return $this->boolean('include_breaks', true);
    }

    public function baseQty(): int
    {
        return (int) $this->input('base_qty', 1);
    }
}
