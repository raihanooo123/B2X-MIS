<?php

use App\Domain\Identity\LoginThrottle;
use App\Domain\Identity\Totp;

/**
 * RFC 6238 Appendix B's SHA-1 vectors (secret "12345678901234567890"),
 * truncated to our 6 digits — the last six of the RFC's eight.
 */
it('matches the RFC 6238 SHA-1 test vectors', function (int $time, string $code) {
    $secret = Totp::base32Encode('12345678901234567890');

    expect(Totp::codeAt($secret, intdiv($time, 30)))->toBe($code)
        ->and(Totp::verify($secret, $code, null, $time))->toBeTrue();
})->with([
    [59, '287082'],
    [1111111109, '081804'],
    [1111111111, '050471'],
    [1234567890, '005924'],
    [2000000000, '279037'],
]);

it('accepts one step of drift either side and nothing further', function () {
    $secret = Totp::generateSecret();
    $now = 1_700_000_000;
    $step = intdiv($now, 30);

    expect(Totp::verify($secret, Totp::codeAt($secret, $step - 1), null, $now))->toBeTrue()
        ->and(Totp::verify($secret, Totp::codeAt($secret, $step + 1), null, $now))->toBeTrue()
        ->and(Totp::verify($secret, Totp::codeAt($secret, $step - 2), null, $now))->toBeFalse()
        ->and(Totp::verify($secret, 'abcdef', null, $now))->toBeFalse()
        ->and(Totp::verify($secret, '12345', null, $now))->toBeFalse();
});

it('round-trips base32', function () {
    $bytes = random_bytes(20);

    expect(Totp::base32Decode(Totp::base32Encode($bytes)))->toBe($bytes)
        ->and(strlen(Totp::generateSecret()))->toBe(32);
});

it('builds an otpauth URI an authenticator app understands', function () {
    $uri = Totp::provisioningUri('JBSWY3DPEHPK3PXP', 'buyer@example.com', 'B2X Wholesale');

    expect($uri)->toStartWith('otpauth://totp/B2X%20Wholesale%3Abuyer%40example.com?')
        ->and($uri)->toContain('secret=JBSWY3DPEHPK3PXP')
        ->and($uri)->toContain('digits=6')
        ->and($uri)->toContain('period=30');
});

it('locks out on the decided ladder: 5 → 1 min, 10 → 5 min, 15 and beyond → 30 min', function () {
    expect(LoginThrottle::lockSecondsFor(4))->toBe(0)
        ->and(LoginThrottle::lockSecondsFor(5))->toBe(60)
        ->and(LoginThrottle::lockSecondsFor(9))->toBe(0)
        ->and(LoginThrottle::lockSecondsFor(10))->toBe(300)
        ->and(LoginThrottle::lockSecondsFor(14))->toBe(0)
        ->and(LoginThrottle::lockSecondsFor(15))->toBe(1800)
        ->and(LoginThrottle::lockSecondsFor(40))->toBe(1800);
});
