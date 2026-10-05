<?php

namespace App\Http\Requests\Web\Collections;

use Illuminate\Foundation\Http\FormRequest;

/** 05.6 §7A.6: a wrongly keyed cash payment is voided with a reason, before handover. */
class VoidCashRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
