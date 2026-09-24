<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/** 05.13 §6.3. Membership is checked by the controller against the user's own companies. */
class ChooseCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['company' => ['required', 'string', 'ulid']];
    }

    public function companyPublicId(): string
    {
        return (string) $this->validated('company');
    }
}
