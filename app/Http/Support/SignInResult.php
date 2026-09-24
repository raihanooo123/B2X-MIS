<?php

namespace App\Http\Support;

/**
 * The outcome of one sign-in step. `failed` carries no reason on purpose:
 * wrong password, unknown email and an inactive account look identical
 * (05.13 §6.1 steps 3–4).
 */
final readonly class SignInResult
{
    private function __construct(
        public string $outcome,
        public int $lockedSeconds = 0,
    ) {}

    public static function signedIn(): self
    {
        return new self('signed_in');
    }

    public static function twoFactorRequired(): self
    {
        return new self('two_factor_required');
    }

    public static function failed(): self
    {
        return new self('failed');
    }

    public static function locked(int $seconds): self
    {
        return new self('locked', $seconds);
    }

    public function is(string $outcome): bool
    {
        return $this->outcome === $outcome;
    }
}
