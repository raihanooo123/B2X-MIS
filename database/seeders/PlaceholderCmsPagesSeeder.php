<?php

namespace Database\Seeders;

use App\Domain\Cms\CmsPublisher;
use App\Domain\Cms\PageKey;
use App\Models\CmsPage;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * 05.11 §2.4: clearly marked placeholder text for the five legal and help
 * pages, so the footer links and pages can be exercised on a fresh local
 * database. Local only — it throws anywhere else, and CmsPublisher refuses
 * text marked PLACEHOLDER outside `local` as a second guard. Real pages
 * are written (the privacy notice by a solicitor) and published under
 * Content → Pages.
 *
 * Published by the demo administrator from DemoDataSeeder. A page that
 * already has a version is left alone, so running it again is a no-op.
 */
class PlaceholderCmsPagesSeeder extends Seeder
{
    public const ADMIN_EMAIL = 'admin@example.com';

    public function run(): void
    {
        // Never in production, whatever else is configured; and only ever locally.
        if (app()->isProduction()) {
            throw new RuntimeException('PlaceholderCmsPagesSeeder must never run in production.');
        }
        if (! app()->environment('local')) {
            throw new RuntimeException('PlaceholderCmsPagesSeeder may only run in the local environment.');
        }

        $admin = User::query()->where('email', self::ADMIN_EMAIL)->first();
        if ($admin === null) {
            throw new RuntimeException('Run DemoDataSeeder first: placeholder pages are published by '.self::ADMIN_EMAIL.'.');
        }

        foreach (PageKey::cases() as $key) {
            $page = CmsPage::query()->where('page_key', $key->value)->first();
            if ($page === null || $page->versions()->exists()) {
                continue;
            }

            app(CmsPublisher::class)->publishPlaceholder($admin, $key, $key->label(), $this->body($key));
        }
    }

    private function body(PageKey $key): string
    {
        $intro = match ($key) {
            PageKey::Privacy => 'How we collect, use and keep your personal data, and your rights under UK GDPR.',
            PageKey::Cookies => 'This site uses only the cookies it needs to work and to keep it secure.',
            PageKey::Delivery => 'Where we deliver, how long it takes, and collecting from our warehouse.',
            PageKey::Returns => 'How to cancel an order or return goods.',
            PageKey::Contact => 'Our opening hours and how to find us.',
        };

        return <<<MD
            # PLACEHOLDER — NOT REAL CONTENT

            **Do not use in production.** {$intro} This text exists only so the page can be
            exercised on a development machine.

            Real content must be written for the business (the privacy notice by a solicitor) and
            published by an administrator under Content → Pages.
            MD;
    }
}
