<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Docker sets CACHE_STORE=file; PHPUnit <env> may not override before the app boots. Always use in-memory cache for this base test class so rate limits (and similar) do not leak across cases.
        $this->app['config']->set('cache.default', 'array');
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance('cache.store');
        $this->app->forgetInstance(\Illuminate\Cache\RateLimiter::class);

        Cache::flush();
    }
}
