<?php

namespace App\Http\Requests\Api\V1\Credit;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/v1/orders/{id}/pay (05.2 §18.1): the PaymentIntent the browser authorised. Never card details. */
class PayApprovedOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['payment_intent_id' => ['required', 'string', 'regex:/^pi_[A-Za-z0-9]{8,64}$/']];
    }

    public function paymentIntentId(): string
    {
        return (string) $this->validated('payment_intent_id');
    }
}
