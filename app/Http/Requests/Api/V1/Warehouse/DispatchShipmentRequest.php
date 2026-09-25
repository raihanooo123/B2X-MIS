<?php

namespace App\Http\Requests\Api\V1\Warehouse;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST …/shipments/{id}/dispatch — 05.5 §7. Idempotency-Key required
 * (06 §6); the durable guarantee is the shipment itself, which dispatches
 * once (DispatchService). A delivery needs a carrier; a collection
 * records who collected in `note`.
 */
class DispatchShipmentRequest extends FormRequest
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
            'carrier' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tracking_number' => ['sometimes', 'nullable', 'string', 'max:128'],
            'parcel_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'total_weight_g' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function carrier(): ?string
    {
        return $this->optionalString('carrier');
    }

    public function trackingNumber(): ?string
    {
        return $this->optionalString('tracking_number');
    }

    public function parcelCount(): ?int
    {
        $v = $this->validated('parcel_count');

        return $v === null ? null : (int) $v;
    }

    public function totalWeightG(): ?int
    {
        $v = $this->validated('total_weight_g');

        return $v === null ? null : (int) $v;
    }

    public function note(): ?string
    {
        return $this->optionalString('note');
    }

    private function optionalString(string $key): ?string
    {
        $v = $this->validated($key);

        return $v === null || trim((string) $v) === '' ? null : trim((string) $v);
    }
}
