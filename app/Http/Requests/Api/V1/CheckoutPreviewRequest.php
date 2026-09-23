<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/checkout/preview (06 §9.2).
 *
 * `delivery_address_id` is in 06 §9.2's example but cannot be honoured:
 * `addresses` has no `public_id` (02 §4.5), and 06 §2 forbids accepting
 * its internal id. It is rejected explicitly rather than silently
 * ignored (06 §15: unknown input is rejected, not ignored). Until the
 * schema gains one, the delivery country comes from, in order:
 *
 *   1. `delivery_country_code` (additive field, 06 §2 versioning rule)
 *   2. the company's default delivery address
 *
 * and neither is a 422 — never a silent 'GB' (see OrderPricingPipeline's
 * docblock on why a guessed country is a wrong VAT rate).
 *
 * `apply_account_credit` is accepted; the account-balance ledger it
 * would draw on (05.4 §7.5A) is not built, so it currently applies 0.
 */
class CheckoutPreviewRequest extends FormRequest
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
            'delivery_address_id' => ['prohibited'],
            'delivery_country_code' => ['sometimes', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'fulfilment_type' => ['sometimes', 'string', 'in:delivery,collection,dropship'],
            'apply_account_credit' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'delivery_address_id.prohibited' => 'Addresses cannot be referenced by id yet; omit this to use the account\'s default delivery address, or send delivery_country_code.',
        ];
    }

    public function deliveryCountryCode(): ?string
    {
        $code = $this->validated('delivery_country_code');

        return $code === null ? null : (string) $code;
    }

    public function fulfilmentType(): string
    {
        return (string) ($this->validated('fulfilment_type') ?? 'delivery');
    }
}
