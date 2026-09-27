<?php

namespace App\Http\Requests\Auth;

use App\Domain\Accounts\AcceptedTerms;
use App\Domain\Accounts\LegalForm;
use App\Domain\Accounts\TermsKind;
use App\Domain\Delivery\Postcode;
use App\Domain\Identity\BusinessType;
use App\Domain\Identity\Registration;
use App\Http\Requests\Concerns\AuthFields;
use App\Models\TermsVersion;
use App\Rules\UkVatNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * 05.13 §5.1 — apply for a trade account and create the login in one
 * step. Fields are 05.2 §5.1's, as far as 02 §4.6 can store them:
 * trading name and requested tier have no column on `b2b_applications`,
 * and supporting documents (attachments) are a follow-up, so none of the
 * three is asked for yet.
 *
 * 02 §25.1, §25.3: the legal form, and the terms version the applicant
 * read. If the terms changed while the form was open, the submission is
 * refused and the page shows the new version (05.13 §5.1).
 */
class RegisterTradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $upper = fn (string $key) => is_string($this->input($key)) ? strtoupper(trim((string) $this->input($key))) : $this->input($key);

        $this->merge([
            'vat_number' => is_string($this->input('vat_number')) && trim((string) $this->input('vat_number')) !== ''
                ? UkVatNumber::normalise((string) $this->input('vat_number'))
                : null,
            'registration_number' => ($reg = $upper('registration_number')) === '' ? null : $reg,
            'address' => array_merge((array) $this->input('address', []), [
                'postcode' => is_string($this->input('address.postcode')) ? Postcode::format((string) $this->input('address.postcode')) : null,
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => AuthFields::email(),
            'phone' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9 ()\-]{7,}$/'],
            'password' => AuthFields::newPassword(),
            'company_name' => ['required', 'string', 'min:2', 'max:191'],
            'legal_form' => ['required', Rule::enum(LegalForm::class)],
            // Companies House: 8 digits, or 2 letters + 6 digits (05.2 §5.1),
            // required for a limited company or LLP (02 §25.3).
            'registration_number' => [
                Rule::requiredIf(fn (): bool => LegalForm::tryFrom((string) $this->input('legal_form'))?->requiresCompaniesHouseNumber() === true),
                'nullable', 'string', 'regex:/^(\d{8}|[A-Z]{2}\d{6})$/',
            ],
            'vat_number' => ['nullable', 'string', new UkVatNumber],
            'business_type' => ['required', Rule::enum(BusinessType::class)],
            // Whole pounds; stored as minor units (CLAUDE.md invariant 1).
            'estimated_monthly_spend' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'address' => ['required', 'array'],
            'address.line1' => ['required', 'string', 'max:191'],
            'address.line2' => ['nullable', 'string', 'max:191'],
            'address.city' => ['required', 'string', 'max:100'],
            'address.county' => ['nullable', 'string', 'max:100'],
            'address.postcode' => ['required', 'string', 'regex:/^[A-Z]{1,2}\d[A-Z\d]? ?\d[A-Z]{2}$/'],
            'terms' => ['accepted'],
            'terms_version_id' => ['required', 'integer'],
        ];
    }

    /**
     * 02 §25.1: the version read must still be the one in force.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('terms_version_id')) {
                    return;
                }
                if (TermsVersion::current(TermsKind::Trade)?->id !== (int) $this->input('terms_version_id')) {
                    $validator->errors()->add('terms_version_id', Registration::TERMS_CHANGED);
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'registration_number.regex' => 'Enter a Companies House number: 8 digits, or 2 letters and 6 digits.',
            'registration_number.required' => 'Enter the Companies House number. Limited companies and LLPs always have one.',
            'legal_form.required' => 'Choose the legal form of your business.',
            'address.postcode.regex' => 'Enter a valid UK postcode.',
            'phone.regex' => 'Enter a valid phone number.',
        ];
    }

    /** @return array{first_name: string, last_name: string, email: string, password: string, phone: string} */
    public function person(): array
    {
        return [
            'first_name' => trim((string) $this->validated('first_name')),
            'last_name' => trim((string) $this->validated('last_name')),
            'email' => trim((string) $this->validated('email')),
            'password' => (string) $this->validated('password'),
            'phone' => trim((string) $this->validated('phone')),
        ];
    }

    /** 02 §25.1: the version accepted, with the client address and browser. */
    public function acceptedTerms(): AcceptedTerms
    {
        return new AcceptedTerms((int) $this->validated('terms_version_id'), $this->ip(), $this->userAgent());
    }

    /** @return array{company_name: string, legal_form: LegalForm, registration_number: ?string, vat_number: ?string, business_type: BusinessType, estimated_monthly_spend_minor: ?int, address: array<string, string|null>} */
    public function application(): array
    {
        $spend = $this->validated('estimated_monthly_spend');
        /** @var array<string, string|null> $address */
        $address = $this->validated('address');

        return [
            'company_name' => trim((string) $this->validated('company_name')),
            'legal_form' => LegalForm::from((string) $this->validated('legal_form')),
            'registration_number' => $this->validated('registration_number'),
            'vat_number' => $this->validated('vat_number'),
            'business_type' => BusinessType::from((string) $this->validated('business_type')),
            'estimated_monthly_spend_minor' => $spend === null ? null : (int) $spend * 100,
            'address' => [
                'line1' => $address['line1'] ?? null,
                'line2' => $address['line2'] ?? null,
                'city' => $address['city'] ?? null,
                'county' => $address['county'] ?? null,
                'postcode' => $address['postcode'] ?? null,
                'country_code' => 'GB',
            ],
        ];
    }
}
