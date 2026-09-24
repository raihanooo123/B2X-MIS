<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum starts a session on /api only for first-party requests,
        // recognised by Referer/Origin (06 §1). A browser on the app sends
        // one; tests say they are that browser.
        $this->withHeader('Referer', rtrim((string) config('app.url'), '/').'/');
    }
}
