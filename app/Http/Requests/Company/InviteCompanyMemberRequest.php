<?php

namespace App\Http\Requests\Company;

use App\Domain\Identity\CompanyMemberSettings;
use App\Http\Requests\Concerns\AuthFields;
use App\Models\Company;
use App\Models\CompanyInvitation;
use Illuminate\Foundation\Http\FormRequest;

class InviteCompanyMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company && ($this->user()?->can('create', [CompanyInvitation::class, $company]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...self::contactRules(), ...CompanyMemberSettings::rules()];
    }

    /** @return array<string, mixed> */
    public static function contactRules(): array
    {
        return ['email' => AuthFields::email(), 'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return CompanyMemberSettings::messages();
    }
}
