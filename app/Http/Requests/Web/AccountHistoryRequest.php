<?php

namespace App\Http\Requests\Web;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use JsonException;

/** Encrypted keyset cursor retains PostgreSQL timestamp precision and its id tie-breaker. */
class AccountHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['cursor' => ['nullable', 'string', 'max:4096']];
    }

    /** @return array{at: string, id: int}|null */
    public function cursor(string $kind): ?array
    {
        $value = $this->validated('cursor');
        if ($value === null) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString((string) $value), true, flags: JSON_THROW_ON_ERROR);
            if (is_array($data) && ($data['kind'] ?? null) === $kind && ($data['user'] ?? null) === $this->user()?->getAuthIdentifier()
                && is_string($data['at'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:[+-]\d{2}(?::\d{2})?)?$/D', $data['at'])
                && is_int($data['id'] ?? null) && $data['id'] > 0) {
                return ['at' => $data['at'], 'id' => $data['id']];
            }
        } catch (DecryptException|JsonException) {
            // Reject malformed cursors rather than silently restarting page one.
        }
        throw ValidationException::withMessages(['cursor' => 'This page link is invalid. Open the first page again.']);
    }
}
