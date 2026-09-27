<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $cacheDir = dirname(__DIR__).'/bootstrap/cache/';
        if (is_file($cacheDir.'config.php')
            || is_file($cacheDir.'events.php')
            || (glob($cacheDir.'routes-*.php') ?: []) !== []) {
            throw new \RuntimeException('Cached Laravel config, routes or events are present. Clear them before running tests so phpunit.xml selects the test database and fresh listeners.');
        }

        parent::setUp();

        // Sanctum starts a session on /api only for first-party requests,
        // recognised by Referer/Origin (06 §1). A browser on the app sends
        // one; tests say they are that browser.
        $this->withHeader('Referer', rtrim((string) config('app.url'), '/').'/');
    }
}
