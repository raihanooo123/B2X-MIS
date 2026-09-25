<?php

namespace App\Http\Requests\Api\V1\Warehouse;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Addresses one pick line within a shipment: the order line number and,
 * for batch-tracked stock, the batch code — together the allocation's
 * `(order line, location, batch)` identity (stock_allocations_identity_uq).
 * Allocations have no public id, so this is how the client names one.
 */
class PickLineRequest extends FormRequest
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
            'line_no' => ['required', 'integer', 'min:1', 'max:32767'],
            'batch_code' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    public function lineNo(): int
    {
        return (int) $this->validated('line_no');
    }

    public function batchCode(): ?string
    {
        $code = $this->validated('batch_code');

        return $code === null || trim((string) $code) === '' ? null : trim((string) $code);
    }
}
