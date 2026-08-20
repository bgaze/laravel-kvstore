<?php

declare(strict_types=1);

namespace Bgaze\KvStore\Tests;

use Illuminate\Support\Facades\Config;

/**
 * The defaults are merged by the service provider, so they are available
 * without the consumer publishing the config file.
 */
class ConfigurationDefaultsTest extends TestCase
{
    public function test_the_defaults_are_available_without_publishing(): void
    {
        $this->assertSame('kvstore', Config::get('kvstore.table'));
        $this->assertNull(Config::get('kvstore.cache.store'));
        $this->assertSame('kvstore', Config::get('kvstore.cache.key'));
        $this->assertTrue(Config::get('kvstore.cache.auto_invalidate'));
    }

    public function test_the_published_config_file_matches_those_defaults(): void
    {
        $published = require __DIR__.'/../config/kvstore.php';

        $this->assertSame(Config::get('kvstore'), $published);
    }
}
