<?php

declare(strict_types=1);

namespace Timvandendries\PhpRssParser\Tests;

use Timvandendries\PhpRssParser\RssParser;
use Timvandendries\PhpRssParser\Tests\Support\FeedServer;

/**
 * Base class for tests that fetch from the local feed server (see Fixtures/router.php).
 * The server runs on 127.0.0.1, so fetches from it need $allowPrivateNetworks = true.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    private static ?FeedServer $server = NULL;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$server = new FeedServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== NULL) {
            self::$server->stop();
            self::$server = NULL;
        }
        parent::tearDownAfterClass();
    }

    protected static function serverUrl(string $path): string
    {
        if (self::$server === NULL) {
            throw new \LogicException('The feed server only runs during a test class');
        }
        return self::$server->url($path);
    }

    protected static function fixturePath(string $name): string
    {
        return __DIR__ . '/Fixtures/feeds/' . $name;
    }

    /**
     * Calls a private static method of RssParser, for internals that can't be reached through the public API.
     *
     * @param mixed ...$arguments
     * @return mixed
     */
    protected static function callPrivate(string $method, ...$arguments)
    {
        $reflection = new \ReflectionMethod(RssParser::class, $method);
        // Needed before PHP 8.1, deprecated since PHP 8.5.
        if (PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }
        return $reflection->invoke(NULL, ...$arguments);
    }
}
