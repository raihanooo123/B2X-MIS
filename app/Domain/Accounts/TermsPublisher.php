<?php

namespace App\Domain\Accounts;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Domain\Cms\CurrentPages;
use App\Models\Role;
use App\Models\TermsVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 02 §25.1: publishes a terms version. A version is never edited — the
 * `terms_versions_immutable` trigger refuses it — so a correction is a new
 * version, effective now. Takes effect now or later, never in the past,
 * so an acceptance already recorded can never be re-attributed to a
 * version published afterwards.
 *
 * Production terms must be reviewed by a solicitor first: this publishes
 * what it is given. The `placeholder-` label prefix is reserved for
 * PlaceholderTermsSeeder, which may only run locally (02 §25.10).
 */
final class TermsPublisher
{
    public const PLACEHOLDER_PREFIX = 'placeholder-';

    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger,
    ) {}

    /**
     * @param  DateTimeInterface|null  $effectiveFrom  null for "now"
     */
    public function publish(User $actor, TermsKind $kind, string $version, string $bodyMarkdown, ?DateTimeInterface $effectiveFrom = null): TermsVersion
    {
        $version = trim($version);
        if (str_starts_with(strtolower($version), self::PLACEHOLDER_PREFIX)) {
            throw ValidationException::withMessages(['version' => 'Version labels starting "placeholder-" are reserved for development placeholder terms.']);
        }

        return $this->insert($actor, $kind, $version, $bodyMarkdown, $effectiveFrom);
    }

    /**
     * PlaceholderTermsSeeder only. Refused outside the `local` environment,
     * so placeholder text can never become the terms in force elsewhere.
     */
    public function publishPlaceholder(User $actor, TermsKind $kind, string $version, string $bodyMarkdown): TermsVersion
    {
        if (! app()->environment('local')) {
            throw new LogicException('Placeholder terms may only be published in the local environment.');
        }
        if (! str_starts_with($version, self::PLACEHOLDER_PREFIX)) {
            throw new LogicException('Placeholder terms must use the "placeholder-" version prefix.');
        }

        return $this->insert($actor, $kind, $version, $bodyMarkdown, null);
    }

    private function insert(User $actor, TermsKind $kind, string $version, string $bodyMarkdown, ?DateTimeInterface $effectiveFrom): TermsVersion
    {
        $terms = $this->insertVersion($actor, $kind, $version, $bodyMarkdown, $effectiveFrom);

        // The storefront footer's cached `/terms` link (05.11 §2.1).
        DB::afterCommit(fn () => CurrentPages::forget());

        return $terms;
    }

    private function insertVersion(User $actor, TermsKind $kind, string $version, string $bodyMarkdown, ?DateTimeInterface $effectiveFrom): TermsVersion
    {
        if (preg_match('/^[0-9A-Za-z._-]{1,32}$/', $version) !== 1) {
            throw ValidationException::withMessages(['version' => 'Use up to 32 letters, digits, dots, hyphens or underscores.']);
        }
        if (trim($bodyMarkdown) === '') {
            throw ValidationException::withMessages(['body_markdown' => 'Enter the text of the terms.']);
        }

        $now = CarbonImmutable::now();
        $effective = $effectiveFrom === null ? $now : CarbonImmutable::instance($effectiveFrom);
        if ($effective->lessThan($now)) {
            throw ValidationException::withMessages(['effective_from' => 'A version takes effect now or later, never in the past.']);
        }

        try {
            return DB::transaction(function () use ($actor, $kind, $version, $bodyMarkdown, $effective): TermsVersion {
                // As the staff services: the actor's authority is read after
                // any competing role change commits.
                Role::query()->where('code', 'admin')->lockForUpdate()->first();
                $currentActor = User::query()->findOrFail($actor->id);
                Gate::forUser($currentActor)->authorize('create', TermsVersion::class);

                $terms = TermsVersion::query()->create([
                    'kind' => $kind->value,
                    'version' => $version,
                    'body_markdown' => $bodyMarkdown,
                    'body_sha256' => hash('sha256', $bodyMarkdown),
                    'effective_from' => $effective,
                    'published_by_user_id' => $currentActor->id,
                ]);

                $this->audit->record(new AuditEntry(
                    action: AuditAction::TermsVersionPublished,
                    actorType: 'user',
                    actorUserId: $currentActor->id,
                    subjectType: 'terms_version',
                    subjectId: $terms->id,
                    after: [
                        'kind' => $kind->value,
                        'version' => $version,
                        'effective_from' => $effective->toIso8601String(),
                        'body_sha256' => $terms->body_sha256,
                    ],
                ));

                return $terms;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw str_contains($exception->getMessage(), 'terms_versions_kind_effective_uq')
                ? ValidationException::withMessages(['effective_from' => 'Another version of these terms takes effect at exactly that moment.'])
                : ValidationException::withMessages(['version' => "Version {$version} of these terms already exists."]);
        }
    }
}
