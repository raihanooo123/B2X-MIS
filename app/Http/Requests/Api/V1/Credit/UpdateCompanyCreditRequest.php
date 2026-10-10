<?php

namespace App\Http\Requests\Api\V1\Credit;

use App\Domain\Billing\PaymentTerms;
use App\Domain\Credit\CreditSettings;
use App\Domain\Identity\CompanyMemberSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/companies/{id}/credit (05.2 §18.3), accounts/admin. The
 * limit is typed in pounds and converted once, with integer arithmetic
 * (CompanyMemberSettings::poundsToMinor, CLAUDE.md invariant 1).
 */
class UpdateCompanyCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'credit_limit' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'payment_terms' => ['required', Rule::enum(PaymentTerms::class)],
            'status' => ['required', Rule::in(['approved', 'suspended'])],
            'suspension_reason' => ['required_if:status,suspended', 'nullable', Rule::in(CreditSettings::SUSPENSION_REASONS)],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['credit_limit.regex' => 'Enter the credit limit in pounds, e.g. 5000 or 5000.50.'];
    }

    public function limitMinor(): int
    {
        return CompanyMemberSettings::poundsToMinor((string) $this->validated('credit_limit'));
    }
}
