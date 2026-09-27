<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\Concerns\AuthFields;
use Illuminate\Foundation\Http\FormRequest;

class AcceptCompanyInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The token/recipient policy is checked again under lock in the service.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Uniform validation for guests; never condition it on an email lookup.
        return $this->user() === null ? ['password' => AuthFields::newPassword()] : [];
    }
}
