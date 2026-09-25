<?php

namespace App\Http\Requests\Api\V1\Warehouse;

use App\Domain\Warehouse\ReceiptSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/warehouse/receipts (06 §8) — 05.5 §4.2 step 1. `reference`
 * is the scanned PO number or container reference; a manual receipt names
 * its location by code instead. Authorisation is GoodsReceiptPolicy, in
 * the controller.
 */
class OpenGoodsReceiptRequest extends FormRequest
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
            'source' => ['required', Rule::enum(ReceiptSource::class)],
            'reference' => ['required_unless:source,manual', 'prohibited_if:source,manual', 'nullable', 'string', 'max:64'],
            'location_code' => ['required_if:source,manual', 'prohibited_unless:source,manual', 'nullable', 'string', 'max:64'],
        ];
    }

    public function source(): ReceiptSource
    {
        return ReceiptSource::from((string) $this->validated('source'));
    }

    public function reference(): ?string
    {
        $reference = $this->validated('reference');

        return $reference === null ? null : trim((string) $reference);
    }

    public function locationCode(): ?string
    {
        $code = $this->validated('location_code');

        return $code === null ? null : trim((string) $code);
    }
}
