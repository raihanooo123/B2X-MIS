<?php

use App\Domain\Inventory\DeadlockRetryPolicy;
use Illuminate\Database\QueryException;

function fakeQueryException(string $sqlState): QueryException
{
    $previous = new PDOException('simulated');
    $previous->errorInfo = [$sqlState, 1, 'simulated'];

    return new QueryException('pgsql', 'select 1', [], $previous);
}

it('returns the operation result on first success without retrying', function () {
    $calls = 0;

    $result = (new DeadlockRetryPolicy)->run(function () use (&$calls) {
        $calls++;

        return 'ok';
    }, 'test');

    expect($result)->toBe('ok')
        ->and($calls)->toBe(1);
});

it('retries on a deadlock SQLSTATE (40P01) and succeeds once contention clears', function () {
    $calls = 0;

    $result = (new DeadlockRetryPolicy)->run(function () use (&$calls) {
        $calls++;
        if ($calls < 2) {
            throw fakeQueryException('40P01');
        }

        return 'ok';
    }, 'test');

    expect($result)->toBe('ok')
        ->and($calls)->toBe(2);
});

it('retries on a lock-timeout SQLSTATE (55P03)', function () {
    $calls = 0;

    $result = (new DeadlockRetryPolicy)->run(function () use (&$calls) {
        $calls++;
        if ($calls < 3) {
            throw fakeQueryException('55P03');
        }

        return 'ok';
    }, 'test');

    expect($result)->toBe('ok')
        ->and($calls)->toBe(3);
});

it('does not retry a non-retryable SQLSTATE', function () {
    $calls = 0;

    expect(function () use (&$calls) {
        (new DeadlockRetryPolicy)->run(function () use (&$calls) {
            $calls++;
            throw fakeQueryException('23505');
        }, 'test');
    })->toThrow(QueryException::class);

    expect($calls)->toBe(1);
});

it('does not retry a non-QueryException', function () {
    $calls = 0;

    expect(function () use (&$calls) {
        (new DeadlockRetryPolicy)->run(function () use (&$calls) {
            $calls++;
            throw new RuntimeException('not a deadlock');
        }, 'test');
    })->toThrow(RuntimeException::class);

    expect($calls)->toBe(1);
});

it('exhausts after 3 retries (4 attempts) and surfaces the last QueryException', function () {
    $calls = 0;

    expect(function () use (&$calls) {
        (new DeadlockRetryPolicy)->run(function () use (&$calls) {
            $calls++;
            throw fakeQueryException('40P01');
        }, 'test');
    })->toThrow(QueryException::class);

    expect($calls)->toBe(4);
});
