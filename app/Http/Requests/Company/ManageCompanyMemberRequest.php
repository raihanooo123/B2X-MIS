<?php

namespace App\Http\Requests\Company;

use App\Domain\Identity\CompanyMemberSettings;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class ManageCompanyMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company && ($this->user()?->can('manageMembers', $company) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->isMethod('delete') ? [] : CompanyMemberSettings::rules();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return CompanyMemberSettings::messages();
    }
}
