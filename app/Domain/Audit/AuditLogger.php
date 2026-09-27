<?php

namespace App\Domain\Audit;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class AuditLogger
{
    public function record(AuditEntry $entry): void
    {
        if (($entry->actorType === 'user') !== ($entry->actorUserId !== null)
            || ! in_array($entry->actorType, ['user', 'system', 'anonymous'], true)) {
            throw new InvalidArgumentException('Invalid audit actor.');
        }
        if (($entry->subjectType === null) !== ($entry->subjectId === null)) {
            throw new InvalidArgumentException('Audit subject type and id must be supplied together.');
        }

        $this->validateFields($entry->before, $entry->action->beforeFields());
        $this->validateFields($entry->after, $entry->action->afterFields());
        match ($entry->action) {
            AuditAction::SignInFailed => $this->validateFailedSignIn($entry),
        };

        DB::table('audit_log')->insert([
            'event_family' => $entry->action->family(),
            'action' => $entry->action->value,
            'actor_type' => $entry->actorType,
            'actor_user_id' => $entry->actorUserId,
            'acting_for_company_id' => $entry->actingForCompanyId,
            'company_id' => $entry->companyId,
            'subject_type' => $entry->subjectType,
            'subject_id' => $entry->subjectId,
            'before' => $entry->before === [] ? null : json_encode($entry->before, JSON_THROW_ON_ERROR),
            'after' => $entry->after === [] ? null : json_encode($entry->after, JSON_THROW_ON_ERROR),
            'reason' => $entry->reason,
            'ip' => $entry->ip,
            'user_agent' => $entry->userAgent,
        ]);
    }

    public function failedSignIn(string $identifier, ?string $ip, ?string $userAgent): void
    {
        $identifier = mb_strtolower(trim($identifier));
        if ($identifier === '') {
            throw new InvalidArgumentException('A failed sign-in requires an identifier.');
        }

        $key = (string) config('audit.identifier_key');
        $version = (string) config('audit.identifier_key_version');
        if (strlen($key) < 32 || preg_match('/^[A-Za-z0-9_-]{1,32}$/', $version) !== 1) {
            throw new RuntimeException('Audit identifier key and version must be configured.');
        }

        $this->record(new AuditEntry(
            action: AuditAction::SignInFailed,
            actorType: 'anonymous',
            after: [
                'identifier_fingerprint' => hash_hmac('sha256', $identifier, $key),
                'key_version' => $version,
            ],
            ip: $ip,
            userAgent: $userAgent,
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $allowed
     */
    private function validateFields(array $payload, array $allowed): void
    {
        foreach ($payload as $key => $value) {
            if (! in_array($key, $allowed, true)
                || (! is_bool($value) && ! is_int($value) && ! is_string($value) && $value !== null)) {
                throw new InvalidArgumentException('Unsupported audit payload field.');
            }
        }
    }

    private function validateFailedSignIn(AuditEntry $entry): void
    {
        if ($entry->actorType !== 'anonymous' || $entry->actorUserId !== null
            || $entry->before !== [] || $entry->reason !== null
            || $entry->subjectType !== null || $entry->subjectId !== null
            || count($entry->after) !== 2
            || ! is_string($entry->after['identifier_fingerprint'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $entry->after['identifier_fingerprint']) !== 1
            || ! is_string($entry->after['key_version'] ?? null)
            || preg_match('/^[A-Za-z0-9_-]{1,32}$/', $entry->after['key_version']) !== 1) {
            throw new InvalidArgumentException('Invalid failed sign-in audit entry.');
        }
    }
}
