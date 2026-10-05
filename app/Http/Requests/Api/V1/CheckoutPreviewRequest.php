<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\SavedDeliveryAddress;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/checkout/preview (06 §9.2).
 *
 * A verified public buyer may send a live owned address ULID (02 §28).
 * Editable delivery fields still determine the quote; the id is checked
 * for ownership, never used as a replacement for the final form fields.
 * Trade buyers keep inline addresses and their default-country fallback.
 *
 * `apply_account_credit` is accepted; the account-balance ledger it
 * would draw on (05.4 §7.5A) is not built, so it currently applies 0.
 */
class CheckoutPreviewRequest extends FormRequest
{
    use SavedDeliveryAddress;

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
            'delivery_address_id' => $this->savedAddressRules(),
            'delivery_country_code' => ['sometimes', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            // 05.6: with a postcode, preview rates carriage; without one
            // (the cart before an address is chosen), `delivery` is null.
            'delivery_postcode' => ['sometimes', 'nullable', 'string', 'max:16'],
            'fulfilment_type' => ['sometimes', 'string', 'in:delivery,collection,dropship'],
            'collection_slot_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'payment_method' => ['sometimes', 'string', 'in:card,bacs,on_account,cash_at_collection'],
            'apply_account_credit' => ['sometimes', 'boolean'],
        ];
    }

    public function deliveryCountryCode(): ?string
    {
        $code = $this->validated('delivery_country_code');

        return $code === null ? null : (string) $code;
    }

    public function deliveryPostcode(): ?string
    {
        $postcode = $this->validated('delivery_postcode');

        return is_string($postcode) && trim($postcode) !== '' ? strtoupper(trim($postcode)) : null;
    }

    public function fulfilmentType(): string
    {
        return (string) ($this->validated('fulfilment_type') ?? 'delivery');
    }

    public function collectionSlotId(): ?int
    {
        $id = $this->validated('collection_slot_id');

        return $id === null ? null : (int) $id;
    }

    public function paymentMethod(): ?string
    {
        $method = $this->validated('payment_method');

        return is_string($method) ? $method : null;
    }
}
