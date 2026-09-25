<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Delivery\DeliveryDestination;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/checkout/card-intent — the card authorisation for the
 * total the buyer is looking at. The delivery address decides the amount:
 * its country sets the VAT (03 §10) and its postcode the carriage (05.6).
 */
class CardIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('delivery_country_code'))) {
            $this->merge(['delivery_country_code' => strtoupper(trim((string) $this->input('delivery_country_code')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'expected_total_gross_minor' => ['required', 'integer', 'min:1'],
            'delivery_country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            // Carriage is part of the amount: no card is authorised before
            // it is known (05.6 §8).
            'delivery_postcode' => ['required', 'string', 'max:16'],
        ];
    }

    public function expectedTotalGrossMinor(): int
    {
        return (int) $this->validated('expected_total_gross_minor');
    }

    public function destination(): DeliveryDestination
    {
        return new DeliveryDestination(strtoupper(trim((string) $this->validated('delivery_postcode'))), $this->deliveryCountryCode());
    }

    public function deliveryCountryCode(): string
    {
        return (string) $this->validated('delivery_country_code');
    }
}
