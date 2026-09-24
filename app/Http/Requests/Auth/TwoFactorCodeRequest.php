<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/** A TOTP code or a recovery code (05.13 §12.3). */
class TwoFactorCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:32']];
    }

    public function code(): string
    {
        return (string) $this->validated('code');
    }
}
