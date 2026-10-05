<?php

namespace App\Domain\Cms;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Models\CmsPage;
use App\Models\CmsPageVersion;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 05.11 §2.2 — saving a page's draft and publishing it as a new, immutable
 * version. Admin only (CmsPagePolicy), re-checked under the admin role
 * lock as every staff service does (TermsPublisher).
 *
 * Publishing copies the draft into `cms_page_versions` with the next
 * version number for that page (taken under the page row's lock, so it is
 * gapless per page), its SHA-256 and the publisher, effective now or
 * later, never in the past. Audited as `content.page_published` without
 * the text. Saving a draft is not audited: nothing public changes, and the
 * published version row is the record.
 */
final class CmsPublisher
{
    public const MAX_META = 320;

    /** Placeholder text is marked with this, and refused outside `local`. */
    public const PLACEHOLDER_MARK = 'PLACEHOLDER';

    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger,
    ) {}

    public function saveDraft(User $actor, PageKey $key, ?string $title, ?string $metaDescription, ?string $bodyMarkdown): CmsPage
    {
        $meta = self::clean($metaDescription);
        if ($meta !== null && mb_strlen($meta) > self::MAX_META) {
            throw ValidationException::withMessages(['draft_meta_description' => 'Keep the description to '.self::MAX_META.' characters or fewer.']);
        }

        return DB::transaction(function () use ($actor, $key, $title, $meta, $bodyMarkdown): CmsPage {
            $currentActor = $this->authorise($actor, 'update');
            $page = $this->lockPage($key);

            $page->forceFill([
                'draft_title' => self::clean($title),
                'draft_meta_description' => $meta,
                'draft_body_markdown' => $bodyMarkdown === null || trim($bodyMarkdown) === '' ? null : $bodyMarkdown,
                'draft_updated_at' => now(),
                'draft_updated_by_user_id' => $currentActor->id,
            ])->save();

            return $page;
        });
    }

    /**
     * @param  DateTimeInterface|null  $effectiveFrom  null for "now"
     */
    public function publish(User $actor, PageKey $key, ?DateTimeInterface $effectiveFrom = null, ?string $changeNote = null): CmsPageVersion
    {
        $now = CarbonImmutable::now();
        $effective = $effectiveFrom === null ? $now : CarbonImmutable::instance($effectiveFrom);
        if ($effective->lessThan($now->subSecond())) {
            throw ValidationException::withMessages(['effective_from' => 'A version takes effect now or later, never in the past.']);
        }

        try {
            $version = DB::transaction(function () use ($actor, $key, $effective, $changeNote): CmsPageVersion {
                $currentActor = $this->authorise($actor, 'publish');
                $page = $this->lockPage($key);

                $title = self::clean($page->draft_title);
                $body = $page->draft_body_markdown;
                if ($title === null) {
                    throw ValidationException::withMessages(['draft_title' => 'Give the page a title before publishing.']);
                }
                if ($body === null || trim($body) === '') {
                    throw ValidationException::withMessages(['draft_body_markdown' => 'Write the page before publishing.']);
                }
                if (! app()->environment('local') && str_contains($body, self::PLACEHOLDER_MARK)) {
                    throw ValidationException::withMessages(['draft_body_markdown' => 'This text is marked as a placeholder. Replace it before publishing.']);
                }

                $next = (int) CmsPageVersion::query()->where('cms_page_id', $page->id)->max('version_no') + 1;

                $version = CmsPageVersion::query()->create([
                    'cms_page_id' => $page->id,
                    'version_no' => $next,
                    'title' => $title,
                    'meta_description' => self::clean($page->draft_meta_description),
                    'body_markdown' => $body,
                    'body_sha256' => hash('sha256', $body),
                    'effective_from' => $effective,
                    'change_note' => self::clean($changeNote),
                    'published_by_user_id' => $currentActor->id,
                ]);

                $this->audit->record(new AuditEntry(
                    action: AuditAction::PagePublished,
                    actorType: 'user',
                    actorUserId: $currentActor->id,
                    subjectType: 'cms_page',
                    subjectId: $page->id,
                    after: [
                        'page_key' => $key->value,
                        'version_no' => $next,
                        'effective_from' => $effective->toIso8601String(),
                        'body_sha256' => $version->body_sha256,
                    ],
                ));

                return $version;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['effective_from' => 'Another version of this page takes effect at exactly that moment.']);
        }

        DB::afterCommit(fn () => CurrentPages::forget());

        return $version;
    }

    /**
     * Local placeholder text only (PlaceholderCmsPagesSeeder): draft and
     * publish in one step. Refused anywhere but `local`.
     */
    public function publishPlaceholder(User $actor, PageKey $key, string $title, string $bodyMarkdown): CmsPageVersion
    {
        if (! app()->environment('local')) {
            throw new LogicException('Placeholder pages may only be published in the local environment.');
        }

        $this->saveDraft($actor, $key, $title, null, $bodyMarkdown);

        return $this->publish($actor, $key, null, 'Development placeholder');
    }

    private function authorise(User $actor, string $ability): User
    {
        Role::query()->where('code', 'admin')->lockForUpdate()->first();
        $currentActor = User::query()->findOrFail($actor->id);
        Gate::forUser($currentActor)->authorize($ability, CmsPage::class);

        return $currentActor;
    }

    private function lockPage(PageKey $key): CmsPage
    {
        return CmsPage::query()->where('page_key', $key->value)->lockForUpdate()->firstOrFail();
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
