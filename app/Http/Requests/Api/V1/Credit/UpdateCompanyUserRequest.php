<?php

namespace App\Http\Requests\Api\V1\Credit;

use App\Domain\Identity\CompanyMemberSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/v1/company-users/{user_id} (05.2 §18.3): a member's role,
 * per-order limit (pounds) and approval flag, in the acting company.
 * CompanyMemberService authorises (`manageMembers`) under its locks.
 */
class UpdateCompanyUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return CompanyMemberSettings::rules();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return CompanyMemberSettings::messages();
    }
}
