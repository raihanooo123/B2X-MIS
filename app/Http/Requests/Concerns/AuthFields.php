<?php

namespace App\Http\Requests\Concerns;

use App\Rules\NotBreachedPassword;
use Illuminate\Validation\Rules\Password;

/**
 * Field rules shared by every form that takes an email or sets a password.
 */
final class AuthFields
{
    /**
     * An email address. `not_regex` refuses CR and LF outright: Laravel 11's
     * `email` rule is subject to a CRLF-injection advisory (GHSA-5vg9-5847-vvmq)
     * fixed only in 12.60+/13.x, and an address with a line break must never
     * reach a mail header.
     *
     * @return list<mixed>
     */
    public static function email(): array
    {
        return ['required', 'string', 'max:254', 'not_regex:/[\r\n]/', 'email:rfc,filter'];
    }

    /**
     * 07 §6.1: at least 12 characters, no composition rules, not breached
     * (offline list, 05.13 §5.4). Hashing is Argon2id (config/hashing.php).
     *
     * @return list<mixed>
     */
    public static function newPassword(): array
    {
        return ['required', 'string', 'max:1024', 'confirmed', Password::min(12), new NotBreachedPassword];
    }
}
