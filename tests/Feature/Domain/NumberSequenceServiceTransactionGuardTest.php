<?php

use App\Domain\Reference\Exceptions\MustRunInsideTransactionException;
use App\Domain\Reference\NumberSequenceService;
use App\Models\NumberSequence;
use Illuminate\Support\Facades\DB;

// Deliberately no RefreshDatabase here: it wraps every test in its own
// transaction for the test's whole duration, which would make
// DB::transactionLevel() permanently > 0 and this exact guard
// untestable. Cleans up its own row explicitly instead.

afterEach(function () {
    DB::table('number_sequences')->where('key_name', 'guard-test-sequence')->delete();
});

it('throws when next() is called with no ambient transaction open', function () {
    NumberSequence::factory()->forSeries('guard-test-sequence', 'GT-')->create(['next_value' => 1, 'padding' => 4]);

    expect(DB::transactionLevel())->toBe(0);

    expect(fn () => (new NumberSequenceService)->next('guard-test-sequence'))
        ->toThrow(MustRunInsideTransactionException::class);

    // proof the failed, guarded attempt consumed nothing
    expect(NumberSequence::find('guard-test-sequence')->next_value)->toBe(1);
});

it('succeeds once a transaction is open', function () {
    NumberSequence::factory()->forSeries('guard-test-sequence', 'GT-')->create(['next_value' => 1, 'padding' => 4]);

    $number = DB::transaction(fn () => (new NumberSequenceService)->next('guard-test-sequence'));

    expect($number)->toBe('GT-0001');
});
