<?php

use App\Domain\Pricing\Money;

it('rounds down when the remainder is less than half the denominator', function () {
    // 34 / 10 = 3.4 -> 3
    expect(Money::roundHalfUpDiv(34, 10))->toBe(3);
});

it('rounds up when the remainder is exactly half the denominator', function () {
    // 35 / 10 = 3.5 -> 4 (half rounds away from zero, 03 §6.2)
    expect(Money::roundHalfUpDiv(35, 10))->toBe(4);
});

it('rounds up when the remainder is more than half the denominator', function () {
    // 36 / 10 = 3.6 -> 4
    expect(Money::roundHalfUpDiv(36, 10))->toBe(4);
});

it('divides exactly with no remainder', function () {
    expect(Money::roundHalfUpDiv(100, 100))->toBe(1);
});

it('handles a zero numerator', function () {
    expect(Money::roundHalfUpDiv(0, 100))->toBe(0);
});

it('reproduces the §3.3 worked example exactly: 9212 e4 x 1440 units', function () {
    // 9212 * 1440 = 13,265,280 e4 -> /100 = 132,652.8 -> half-up -> 132,653 (£1,326.53)
    expect(Money::roundHalfUpDiv(9212 * 1440, 100))->toBe(132653);
});

it('rejects a zero denominator', function () {
    expect(fn () => Money::roundHalfUpDiv(100, 0))->toThrow(InvalidArgumentException::class);
});

it('rejects a negative denominator', function () {
    expect(fn () => Money::roundHalfUpDiv(100, -100))->toThrow(InvalidArgumentException::class);
});
