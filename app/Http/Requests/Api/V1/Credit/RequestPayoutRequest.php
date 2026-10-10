<?php

namespace App\Http\Requests\Api\V1\Credit;

use App\Domain\Identity\CompanyMemberSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/v1/companies/{id}/credit-payouts (05.2 §18.3), accounts/admin. */
class RequestPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'method' => ['required', Rule::in(['original_card', 'bank'])],
            'amount' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'source_payment_id' => ['required_if:method,original_card', 'nullable', 'string', 'ulid'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function amountMinor(): int
    {
        return CompanyMemberSettings::poundsToMinor((string) $this->validated('amount'));
    }
}
