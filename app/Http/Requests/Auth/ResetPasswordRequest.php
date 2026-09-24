<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\AuthFields;
use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'email' => AuthFields::email(),
            'password' => AuthFields::newPassword(),
        ];
    }
}
