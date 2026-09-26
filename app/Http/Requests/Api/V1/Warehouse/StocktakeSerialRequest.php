<?php

namespace App\Http\Requests\Api\V1\Warehouse;

/**
 * POST …/stocktakes/{id}/serials (scan) and …/serials/remove (undo a
 * mis-scan) — one serial of a serial-tracked SKU (02 §24.2).
 */
class StocktakeSerialRequest extends StocktakeIdentityRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'serial_number' => ['required', 'string', 'max:128'],
        ]);
    }

    public function serialNumber(): string
    {
        return trim((string) $this->validated('serial_number'));
    }
}
