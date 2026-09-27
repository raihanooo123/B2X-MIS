<?php

namespace App\Filament\Support;

use App\Domain\Accounts\TermsKind;
use App\Models\B2bApplication;
use App\Models\Company;
use App\Models\TermsVersion;
use App\Models\User;

/**
 * A readable name for an audit row's subject (the audit log viewer): a
 * user's email, a company's name and account code, an application's
 * company name, a terms version. Looked up once per subject per request,
 * so a page of rows about the same few subjects costs a few queries.
 * Null for a subject type without a name, or one that no longer exists.
 */
final class AuditSubjectLabel
{
    /** @var array<string, string|null> */
    private static array $cache = [];

    public static function for(?string $type, ?int $id): ?string
    {
        if ($type === null || $id === null) {
            return null;
        }

        return self::$cache["{$type}:{$id}"] ??= self::lookup($type, $id);
    }

    private static function lookup(string $type, int $id): ?string
    {
        switch ($type) {
            case 'user':
                $email = User::withTrashed()->whereKey($id)->value('email');

                return is_string($email) ? $email : null;
            case 'company':
                $company = Company::query()->find($id, ['name', 'account_code']);

                return $company === null ? null : "{$company->name} ({$company->account_code})";
            case 'b2b_application':
                $name = B2bApplication::query()->whereKey($id)->value('company_name');

                return is_string($name) ? "Application: {$name}" : null;
            case 'terms_version':
                $terms = TermsVersion::query()->find($id, ['kind', 'version']);

                return $terms === null ? null : (TermsKind::tryFrom($terms->kind)?->label() ?? $terms->kind)." {$terms->version}";
            default:
                return null;
        }
    }

    /** "user #12 — asha@example.com", or "user #12" when there is no name. */
    public static function display(?string $type, ?int $id): ?string
    {
        if ($type === null || $id === null) {
            return null;
        }
        $label = self::for($type, $id);

        return "{$type} #{$id}".($label === null ? '' : " — {$label}");
    }
}
