<?php

use App\Domain\Reference\Exceptions\UnknownNumberSequenceException;
use App\Domain\Reference\NumberSequenceService;
use App\Models\NumberSequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

// "Throws outside a transaction" is NOT tested in this file: RefreshDatabase
// itself keeps a transaction open for every test's whole duration, so
// DB::transactionLevel() can never genuinely be 0 here. That specific
// guard has its own file (NumberSequenceServiceTransactionGuardTest.php)
// which deliberately does not use RefreshDatabase.
uses(RefreshDatabase::class);

it('throws for an unprovisioned key_name', function () {
    DB::transaction(function () {
        expect(fn () => (new NumberSequenceService)->next('does_not_exist'))
            ->toThrow(UnknownNumberSequenceException::class);
    });
});

it('formats prefix + zero-padded next_value and increments it', function () {
    NumberSequence::factory()->forSeries('order_number', 'SO-')->create(['next_value' => 42, 'padding' => 6]);

    $number = DB::transaction(fn () => (new NumberSequenceService)->next('order_number'));

    expect($number)->toBe('SO-000042')
        ->and(NumberSequence::find('order_number')->next_value)->toBe(43);
});

it('does not truncate when next_value is wider than the padding', function () {
    NumberSequence::factory()->forSeries('order_number', 'SO-')->create(['next_value' => 1234567, 'padding' => 3]);

    $number = DB::transaction(fn () => (new NumberSequenceService)->next('order_number'));

    expect($number)->toBe('SO-1234567');
});

it('issues consecutive numbers across separate transactions', function () {
    NumberSequence::factory()->forSeries('order_number', 'SO-')->create(['next_value' => 1, 'padding' => 4]);

    $first = DB::transaction(fn () => (new NumberSequenceService)->next('order_number'));
    $second = DB::transaction(fn () => (new NumberSequenceService)->next('order_number'));
    $third = DB::transaction(fn () => (new NumberSequenceService)->next('order_number'));

    expect([$first, $second, $third])->toBe(['SO-0001', 'SO-0002', 'SO-0003']);
});

it('is gapless: a rolled-back caller transaction consumes no number, the next caller gets it instead', function () {
    NumberSequence::factory()->forSeries('order_number', 'SO-')->create(['next_value' => 1, 'padding' => 4]);

    try {
        DB::transaction(function () {
            $number = (new NumberSequenceService)->next('order_number');
            expect($number)->toBe('SO-0001');

            throw new RuntimeException('simulated failure elsewhere in the document-creation transaction');
        });
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('simulated failure elsewhere in the document-creation transaction');
    }

    // the increment rolled back with everything else in that transaction
    expect(NumberSequence::find('order_number')->next_value)->toBe(1);

    // the next real (committed) caller gets SO-0001, not SO-0002 — no gap
    $number = DB::transaction(fn () => (new NumberSequenceService)->next('order_number'));
    expect($number)->toBe('SO-0001');
    expect(NumberSequence::find('order_number')->next_value)->toBe(2);
});

it('keeps independent counters per key_name', function () {
    NumberSequence::factory()->forSeries('order_number', 'SO-')->create(['next_value' => 1, 'padding' => 4]);
    NumberSequence::factory()->forSeries('invoice_number', 'INV-')->create(['next_value' => 500, 'padding' => 5]);

    $order = DB::transaction(fn () => (new NumberSequenceService)->next('order_number'));
    $invoice = DB::transaction(fn () => (new NumberSequenceService)->next('invoice_number'));

    expect($order)->toBe('SO-0001')
        ->and($invoice)->toBe('INV-00500');
    expect(NumberSequence::find('order_number')->next_value)->toBe(2)
        ->and(NumberSequence::find('invoice_number')->next_value)->toBe(501);
});

it('locks the row FOR UPDATE', function () {
    NumberSequence::factory()->forSeries('order_number', 'SO-')->create();

    DB::enableQueryLog();
    DB::transaction(fn () => (new NumberSequenceService)->next('order_number'));
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $lockQuery = collect($log)->first(fn (array $q) => str_contains($q['query'], 'number_sequences') && str_contains($q['query'], 'for update'));
    expect($lockQuery)->not->toBeNull();
});
