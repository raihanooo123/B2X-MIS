<?php

use App\Domain\Pricing\SpendBreakApportioner;
use App\Models\OrderSpendBreak;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reproduces the §7A.5 worked example exactly', function () {
    $break = OrderSpendBreak::factory()->create([
        'discount_type' => 'percentage',
        'discount_rate_bp' => 300,
        'min_subtotal_minor' => 100000,
    ]);

    $lines = ['A' => 62000, 'B' => 31000, 'C' => 15000];

    $result = (new SpendBreakApportioner)->apportion($break, 108000, $lines);

    expect($result->discountMinor)->toBe(3240)
        ->and($result->perLine['A'])->toBe(1860)
        ->and($result->perLine['B'])->toBe(930)
        ->and($result->perLine['C'])->toBe(450)
        ->and(array_sum($result->perLine))->toBe($result->discountMinor);
});

it('assigns the rounding remainder to the largest qualifying line (§7A.7 #17)', function () {
    // discount_minor = round_half_up(100000 x 10 / 10000) = 100 exactly.
    $break = OrderSpendBreak::factory()->create([
        'discount_type' => 'percentage',
        'discount_rate_bp' => 10,
        'min_subtotal_minor' => 100000,
    ]);

    $lines = ['big' => 33334, 'mid' => 33333, 'small' => 33333];

    $result = (new SpendBreakApportioner)->apportion($break, 100000, $lines);

    expect($result->discountMinor)->toBe(100)
        ->and($result->perLine['big'])->toBe(34) // 33 + the 1p remainder
        ->and($result->perLine['mid'])->toBe(33)
        ->and($result->perLine['small'])->toBe(33)
        ->and(array_sum($result->perLine))->toBe(100);
});

it('caps a percentage discount at max_discount_minor rather than apportioning proportionally past it (§7A.7 #15)', function () {
    $break = OrderSpendBreak::factory()->create([
        'discount_type' => 'percentage',
        'discount_rate_bp' => 1000, // 10%
        'max_discount_minor' => 5000,
        'min_subtotal_minor' => 100000,
    ]);

    // 10% of 200000 would be 20000, but capped at 5000.
    $lines = ['A' => 150000, 'B' => 50000];

    $result = (new SpendBreakApportioner)->apportion($break, 200000, $lines);

    expect($result->discountMinor)->toBe(5000)
        ->and($result->perLine['A'])->toBe(3750) // 5000 * 150000/200000
        ->and($result->perLine['B'])->toBe(1250)
        ->and(array_sum($result->perLine))->toBe(5000);
});

it('clamps a fixed discount that would exceed the qualifying subtotal (§7A.7 #19)', function () {
    $break = OrderSpendBreak::factory()->fixedAmount(999999)->create(['min_subtotal_minor' => 100000]);

    $lines = ['A' => 60000, 'B' => 40000];

    $result = (new SpendBreakApportioner)->apportion($break, 100000, $lines);

    expect($result->discountMinor)->toBe(100000) // clamped to the full subtotal
        ->and($result->perLine['A'])->toBe(60000)
        ->and($result->perLine['B'])->toBe(40000)
        ->and(array_sum($result->perLine))->toBe(100000);
});

it('applies a fixed discount below the subtotal without clamping', function () {
    $break = OrderSpendBreak::factory()->fixedAmount(5000)->create(['min_subtotal_minor' => 100000]);

    $lines = ['A' => 70000, 'B' => 30000];

    $result = (new SpendBreakApportioner)->apportion($break, 100000, $lines);

    expect($result->discountMinor)->toBe(5000)
        ->and($result->perLine['A'])->toBe(3500)
        ->and($result->perLine['B'])->toBe(1500);
});

it('gives every qualifying line a zero share when the computed discount is zero', function () {
    $break = OrderSpendBreak::factory()->create([
        'discount_type' => 'percentage',
        'discount_rate_bp' => 1,
        'min_subtotal_minor' => 1,
    ]);

    // 0.01% of 1 minor unit rounds to zero.
    $result = (new SpendBreakApportioner)->apportion($break, 1, ['A' => 1]);

    expect($result->discountMinor)->toBe(0)
        ->and($result->perLine['A'])->toBe(0);
});

it('rejects a qualifying subtotal that does not equal the sum of the qualifying lines', function () {
    $break = OrderSpendBreak::factory()->create(['min_subtotal_minor' => 100000]);

    expect(fn () => (new SpendBreakApportioner)->apportion($break, 100000, ['A' => 50000]))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a non-positive qualifying subtotal', function () {
    $break = OrderSpendBreak::factory()->create(['min_subtotal_minor' => 100000]);

    expect(fn () => (new SpendBreakApportioner)->apportion($break, 0, []))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects an empty qualifying line set', function () {
    $break = OrderSpendBreak::factory()->create(['min_subtotal_minor' => 100000]);

    expect(fn () => (new SpendBreakApportioner)->apportion($break, 100000, []))
        ->toThrow(InvalidArgumentException::class);
});
