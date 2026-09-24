<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/checkout/card-intent — the card authorisation for the
 * total the buyer is looking at. The delivery country sets the VAT, so it
 * decides the amount (03 §10).
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
        ];
    }

    public function expectedTotalGrossMinor(): int
    {
        return (int) $this->validated('expected_total_gross_minor');
    }

    public function deliveryCountryCode(): string
    {
        return (string) $this->validated('delivery_country_code');
    }
}
