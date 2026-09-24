<?php

namespace App\Domain\Identity;

use Illuminate\Support\Facades\Cache;

/**
 * RFC 6238 time-based one-time passwords (05.13 §12.2): HMAC-SHA1,
 * 6 digits, 30-second steps — what every authenticator app implements.
 * Written here rather than pulled in as a dependency: the algorithm is
 * a few lines and CLAUDE.md's approved stack names no 2FA package.
 *
 * verify() accepts the current step and one either side (clock drift),
 * and refuses a step already used by that user (replay), remembering the
 * last accepted step in the cache for as long as a code can stay valid.
 */
final class Totp
{
    public const DIGITS = 6;

    public const PERIOD = 30;

    /** Steps accepted either side of now. */
    public const WINDOW = 1;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new 160-bit secret, base32-encoded — RFC 4226's recommended length. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode("{$issuer}:{$account}").'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function codeAt(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * @param  string|null  $replayKey  identifies the user; null skips replay protection (enrolment)
     */
    public static function verify(string $secret, string $code, ?string $replayKey = null, ?int $now = null): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{'.self::DIGITS.'}$/', $code) !== 1) {
            return false;
        }

        $current = intdiv($now ?? now()->getTimestamp(), self::PERIOD);
        $lastUsed = $replayKey === null ? null : Cache::get(self::replayCacheKey($replayKey));

        for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; $step++) {
            if (is_int($lastUsed) && $step <= $lastUsed) {
                continue;
            }
            if (hash_equals(self::codeAt($secret, $step), $code)) {
                if ($replayKey !== null) {
                    Cache::put(self::replayCacheKey($replayKey), $step, self::PERIOD * (2 * self::WINDOW + 2));
                }

                return true;
            }
        }

        return false;
    }

    private static function replayCacheKey(string $replayKey): string
    {
        return 'auth:totp:last-step:'.$replayKey;
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(rtrim(preg_replace('/\s+/', '', $encoded) ?? '', '='));
        $bits = '';
        foreach (str_split($encoded) as $char) {
            $value = strpos(self::BASE32, $char);
            if ($value === false) {
                throw new \InvalidArgumentException('Invalid base32 character.');
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }

        return $out;
    }
}
