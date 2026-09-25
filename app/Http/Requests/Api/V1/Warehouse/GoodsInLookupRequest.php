<?php

namespace App\Http\Requests\Api\V1\Warehouse;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/warehouse/lookup?code=…&receipt=… — resolve a scanned or
 * typed code (05.5 §9, ScanResolver). `receipt` scopes bin codes to the
 * receipt's location and the returned SKU to its expected lines.
 */
class GoodsInLookupRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:128'],
            'receipt' => ['sometimes', 'nullable', 'string', 'ulid'],
        ];
    }

    public function code(): string
    {
        return trim((string) $this->validated('code'));
    }

    public function receiptPublicId(): ?string
    {
        $id = $this->validated('receipt');

        return $id === null ? null : (string) $id;
    }
}
