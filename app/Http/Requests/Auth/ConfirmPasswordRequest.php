<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

/**
 * Re-entering the current password before a security change (05.13
 * §12.2: regenerating recovery codes, turning 2FA off).
 */
class ConfirmPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['password' => ['required', 'string', 'max:1024']];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            $hash = $this->user()?->password_hash;
            if (! is_string($hash) || ! Hash::check((string) $this->input('password'), $hash)) {
                $validator->errors()->add('password', 'That password is not correct.');
            }
        }];
    }
}
