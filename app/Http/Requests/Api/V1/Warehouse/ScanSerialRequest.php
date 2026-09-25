<?php

namespace App\Http\Requests\Api\V1\Warehouse;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST …/shipments/{id}/serial-scans — one scanned serial (05.5 §5.2).
 * Idempotent by nature on `(order_line_id, serial_id)` (05.5 §10), so no
 * Idempotency-Key is required: a repeated scan is answered, not repeated.
 */
class ScanSerialRequest extends FormRequest
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
            'serial_number' => ['required', 'string', 'max:128'],
        ];
    }

    public function serialNumber(): string
    {
        return trim((string) $this->validated('serial_number'));
    }
}
