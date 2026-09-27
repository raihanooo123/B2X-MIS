<?php

namespace Database\Seeders;

use App\Domain\Accounts\TermsKind;
use App\Domain\Accounts\TermsPublisher;
use App\Models\TermsVersion;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * 02 §25.10: clearly marked placeholder terms of trade, so trade
 * registration works on a fresh local database. Local only — it throws
 * anywhere else, and TermsPublisher refuses placeholder labels outside
 * `local` as a second guard. Production terms must be reviewed by a
 * solicitor and published through Settings → Terms.
 *
 * Published by the demo administrator from DemoDataSeeder. Running it
 * again is a no-op.
 */
class PlaceholderTermsSeeder extends Seeder
{
    public const VERSION = 'placeholder-1';

    public const ADMIN_EMAIL = 'admin@example.com';

    private const BODY = <<<'MD'
        # PLACEHOLDER — NOT TERMS OF TRADE

        **Do not use in production.** This text exists only so trade registration can be
        exercised on a development machine. It has no legal effect.

        Real terms of trade must be written or reviewed by a solicitor and published by an
        administrator under Settings → Terms.
        MD;

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('PlaceholderTermsSeeder may only run in the local environment.');
        }

        if (TermsVersion::query()->where('kind', TermsKind::Trade->value)->where('version', self::VERSION)->exists()) {
            return;
        }

        $admin = User::query()->where('email', self::ADMIN_EMAIL)->first();
        if ($admin === null) {
            throw new RuntimeException('Run DemoDataSeeder first: placeholder terms are published by '.self::ADMIN_EMAIL.'.');
        }

        app(TermsPublisher::class)->publishPlaceholder($admin, TermsKind::Trade, self::VERSION, self::BODY);
    }
}
