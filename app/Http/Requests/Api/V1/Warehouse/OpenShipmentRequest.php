<?php

namespace App\Http\Requests\Api\V1\Warehouse;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/warehouse/shipments — start (or resume) picking an order
 * at a location (05.5 §5.1). Location defaults to the default location.
 * Authorisation is ShipmentPolicy, in the controller.
 */
class OpenShipmentRequest extends FormRequest
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
            'order_number' => ['required', 'string', 'max:64'],
            'location_code' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    public function orderNumber(): string
    {
        return trim((string) $this->validated('order_number'));
    }

    public function locationCode(): ?string
    {
        $code = $this->validated('location_code');

        return $code === null ? null : trim((string) $code);
    }
}
