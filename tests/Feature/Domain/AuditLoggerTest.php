<?php

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('audit.identifier_key', str_repeat('a', 32));
    config()->set('audit.identifier_key_version', 'v1');
});

it('writes a pseudonymous failed sign-in without the identifier', function () {
    $logger = new AuditLogger;
    $logger->failedSignIn('  PERSON@Example.com ', '127.0.0.1', 'Test browser');
    $logger->failedSignIn('person@example.com', '127.0.0.2', null);

    $rows = DB::table('audit_log')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->event_family)->toBe('auth')
        ->and($rows[0]->action)->toBe('auth.sign_in_failed')
        ->and($rows[0]->actor_type)->toBe('anonymous')
        ->and($rows[0]->actor_user_id)->toBeNull()
        ->and($rows[0]->ip)->toBe('127.0.0.1')
        ->and($rows[0]->user_agent)->toBe('Test browser')
        ->and($rows[0]->before)->toBeNull()
        ->and($rows[0]->after)->not->toContain('PERSON@Example.com');

    $first = json_decode($rows[0]->after, true, 512, JSON_THROW_ON_ERROR);
    $second = json_decode($rows[1]->after, true, 512, JSON_THROW_ON_ERROR);
    expect($first)->toBe($second)
        ->and($first['identifier_fingerprint'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($first['key_version'])->toBe('v1');
});

it('rejects missing key material and unapproved payload fields before insert', function () {
    config()->set('audit.identifier_key', null);
    expect(fn () => (new AuditLogger)->failedSignIn('person@example.com', null, null))
        ->toThrow(RuntimeException::class);

    expect(fn () => (new AuditLogger)->record(new AuditEntry(
        action: AuditAction::SignInFailed,
        actorType: 'anonymous',
        after: ['email' => 'person@example.com'],
    )))->toThrow(InvalidArgumentException::class);

    expect(fn () => (new AuditLogger)->record(new AuditEntry(
        action: AuditAction::SignInFailed,
        actorType: 'anonymous',
        after: ['identifier_fingerprint' => 'person@example.com', 'key_version' => 'v1'],
    )))->toThrow(InvalidArgumentException::class);

    expect(DB::table('audit_log')->count())->toBe(0);
});

it('commits and rolls back with the enclosing transaction', function () {
    $logger = new AuditLogger;
    expect(fn () => DB::transaction(function () use ($logger) {
        $logger->failedSignIn('rollback@example.com', null, null);
        throw new RuntimeException('business change failed');
    }))->toThrow(RuntimeException::class);

    expect(DB::table('audit_log')->count())->toBe(0);
});

it('blocks update delete and truncate on the parent and a named partition', function () {
    DB::table('audit_log')->insert([
        'occurred_at' => '2026-09-27 12:00:00+00',
        'event_family' => 'auth',
        'action' => AuditAction::SignInFailed->value,
        'actor_type' => 'anonymous',
    ]);
    DB::table('audit_log')->insert([
        'occurred_at' => '2028-01-01 00:00:00+00',
        'event_family' => 'auth',
        'action' => AuditAction::SignInFailed->value,
        'actor_type' => 'anonymous',
    ]);
    $id = DB::table('audit_log_2026')->value('id');

    foreach ([
        ['UPDATE audit_log SET action = ? WHERE id = ?', ['changed', $id]],
        ['DELETE FROM audit_log_2026 WHERE id = ?', [$id]],
        ['TRUNCATE audit_log', []],
        ['TRUNCATE audit_log_2026', []],
        ['TRUNCATE audit_log_default', []],
    ] as [$sql, $bindings]) {
        expect(fn () => DB::transaction(fn () => DB::statement($sql, $bindings)))
            ->toThrow(QueryException::class);
    }

    expect(DB::table('audit_log')->count())->toBe(2)
        ->and(DB::table('audit_log')->where('id', $id)->value('action'))->toBe(AuditAction::SignInFailed->value);
});
