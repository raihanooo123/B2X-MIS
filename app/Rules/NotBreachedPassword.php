<?php

namespace App\Rules;

use App\Domain\Identity\BreachedPasswords;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 07 §6.1: a password on the breached list is refused. Offline — see
 * BreachedPasswords. Replaces Laravel's Password::uncompromised(), which
 * queries the HIBP API over the network (05.13 §5.4).
 */
final class NotBreachedPassword implements ValidationRule
{
    public function __construct(
        private readonly BreachedPasswords $breached = new BreachedPasswords,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && $this->breached->isBreached($value)) {
            $fail('This password has appeared in a data breach. Please choose a different one.');
        }
    }
}
