<?php

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;

/** 05.15 §6.4: same lengths and postcode normalisation as checkout. */
class SaveDeliveryAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // AddressPolicy is applied by the controller and domain service.
    }

    protected function prepareForValidation(): void
    {
        $fields = [];
        foreach (['label', 'contact_name', 'phone', 'line1', 'line2', 'city', 'county', 'postcode', 'country_code'] as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $fields[$key] = in_array($key, ['postcode', 'country_code'], true) ? strtoupper(trim($value)) : trim($value);
            }
        }
        $this->merge($fields);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:191'],
            'contact_name' => ['required', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:32'],
            'line1' => ['required', 'string', 'max:191'],
            'line2' => ['nullable', 'string', 'max:191'],
            'city' => ['required', 'string', 'max:100'],
            'county' => ['nullable', 'string', 'max:100'],
            'postcode' => ['required', 'string', 'max:16'],
            'country_code' => ['required', 'string', 'in:GB'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
