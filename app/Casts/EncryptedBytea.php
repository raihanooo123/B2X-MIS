<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * An application-encrypted secret in a Postgres `bytea` column —
 * `users.two_factor_secret` (02 §4.2). The column holds Laravel's
 * encrypted payload, never the plaintext.
 *
 * pdo_pgsql returns `bytea` as a stream resource, not a string, so the
 * read side drains it first. The encrypted payload is base64 text, which
 * contains no backslash, so binding it as a string parameter into `bytea`
 * cannot be misread as an escape sequence.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class EncryptedBytea implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        return is_string($value) && $value !== '' ? Crypt::decryptString($value) : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Crypt::encryptString((string) $value);
    }
}
