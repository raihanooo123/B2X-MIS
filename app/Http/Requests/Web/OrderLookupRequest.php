<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Concerns\AuthFields;
use Illuminate\Foundation\Http\FormRequest;

/** 05.15 §6.2 *Find my order*: order number and the email it was placed with. */
class OrderLookupRequest extends FormRequest
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
            'order_number' => ['required', 'string', 'max:32'],
            'email' => AuthFields::email(),
        ];
    }

    public function orderNumber(): string
    {
        return strtoupper(trim((string) $this->validated('order_number')));
    }

    public function email(): string
    {
        return mb_strtolower(trim((string) $this->validated('email')));
    }
}
