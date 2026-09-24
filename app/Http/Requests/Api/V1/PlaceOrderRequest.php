<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Ordering\DeliveryAddress;
use App\Domain\Ordering\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/checkout (06 §9.3).
 *
 * `expected_total_gross_minor` is required: the total the buyer last saw
 * from `checkout/preview`. Anything else and the server answers 409
 * `price_changed` with both figures — never a silently repriced order.
 *
 * The delivery address is sent inline and snapshotted onto the order
 * (02 §8.4). `delivery_address_id` stays refused, as on preview:
 * `addresses` has no `public_id` (02 §4.5), and a public customer has no
 * saved addresses at all. The address's country sets the VAT (03 §10).
 *
 * Only delivery is built (collection slots are not, 05.6).
 */
class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $address = (array) $this->input('delivery_address', []);
        foreach (['postcode', 'country_code'] as $key) {
            if (isset($address[$key]) && is_string($address[$key])) {
                $address[$key] = strtoupper(trim($address[$key]));
            }
        }

        $this->merge(['delivery_address' => $address]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Web checkout offers the buyer's real choice; `prepay` is for
            // orders placed outside it (PaymentMethod's docblock).
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)->except([PaymentMethod::Prepay])],
            'expected_total_gross_minor' => ['required', 'integer', 'min:0'],
            'customer_reference' => ['nullable', 'string', 'max:64'],
            'fulfilment_type' => ['sometimes', 'string', 'in:delivery'],
            'apply_account_credit' => ['sometimes', 'boolean'],
            'delivery_address_id' => ['prohibited'],
            'delivery_address' => ['required', 'array'],
            'delivery_address.contact_name' => ['required', 'string', 'max:191'],
            'delivery_address.company_name' => ['nullable', 'string', 'max:191'],
            'delivery_address.phone' => ['nullable', 'string', 'max:32'],
            'delivery_address.line1' => ['required', 'string', 'max:191'],
            'delivery_address.line2' => ['nullable', 'string', 'max:191'],
            'delivery_address.city' => ['required', 'string', 'max:100'],
            'delivery_address.county' => ['nullable', 'string', 'max:100'],
            'delivery_address.postcode' => ['required', 'string', 'max:16'],
            'delivery_address.country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
        ];
    }

    public function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::from((string) $this->validated('payment_method'));
    }

    public function expectedTotalGrossMinor(): int
    {
        return (int) $this->validated('expected_total_gross_minor');
    }

    public function customerReference(): ?string
    {
        $ref = $this->validated('customer_reference');

        return is_string($ref) && trim($ref) !== '' ? trim($ref) : null;
    }

    public function deliveryAddress(): DeliveryAddress
    {
        /** @var array<string, string|null> $a */
        $a = $this->validated('delivery_address');
        $opt = fn (string $key): ?string => isset($a[$key]) && trim((string) $a[$key]) !== '' ? trim((string) $a[$key]) : null;

        return new DeliveryAddress(
            contactName: trim((string) $a['contact_name']),
            companyName: $opt('company_name'),
            phone: $opt('phone'),
            line1: trim((string) $a['line1']),
            line2: $opt('line2'),
            city: trim((string) $a['city']),
            county: $opt('county'),
            postcode: trim((string) $a['postcode']),
            countryCode: (string) $a['country_code'],
        );
    }
}
