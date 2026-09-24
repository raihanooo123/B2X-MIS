<?php

namespace App\Domain\Identity;

use App\Models\User;

/**
 * The email-verification link (05.13 §11): signed, 24-hour expiry, not a
 * sign-in link.
 *
 * Built and checked here with an HMAC over (user, email, expiry) rather
 * than Laravel's temporary signed routes, which in Laravel 11 carry an
 * unpatched path-confusion advisory (GHSA-crmm-hgp2-wgrp; fixed only in
 * 12.61.1+/13.12+). Including the email in the MAC also means a link stops
 * working the moment the address changes.
 */
final class EmailVerificationLink
{
    public const TTL_SECONDS = 86400;

    public static function url(User $user, ?int $now = null): string
    {
        $expires = ($now ?? now()->getTimestamp()) + self::TTL_SECONDS;

        return route('verification.verify', [
            'user' => $user->public_id,
            'expires' => $expires,
            'signature' => self::signature($user, $expires),
        ]);
    }

    public static function isValid(User $user, int $expires, string $signature, ?int $now = null): bool
    {
        return $expires >= ($now ?? now()->getTimestamp()) && hash_equals(self::signature($user, $expires), $signature);
    }

    private static function signature(User $user, int $expires): string
    {
        return hash_hmac('sha256', "email-verify|{$user->public_id}|".mb_strtolower($user->email)."|{$expires}", (string) config('app.key'));
    }
}
