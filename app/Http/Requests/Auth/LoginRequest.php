<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\AuthFields;
use Illuminate\Foundation\Http\FormRequest;

/** 05.13 §6.1. Throttling and the lockout are SignIn's job, not validation's. */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => AuthFields::email(),
            'password' => ['required', 'string', 'max:1024'],
        ];
    }

    public function email(): string
    {
        return (string) $this->validated('email');
    }

    public function password(): string
    {
        return (string) $this->validated('password');
    }
}
