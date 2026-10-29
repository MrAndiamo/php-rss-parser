<?php

declare(strict_types=1);

namespace Timvandendries\PhpRssParser\Tests;

use Timvandendries\PhpRssParser\objects\FeedObject;
use Timvandendries\PhpRssParser\objects\ItemObject;
use Timvandendries\PhpRssParser\RssParser;
use Timvandendries\PhpRssParser\Tests\Support\FeedServer;

/**
 * Base class for tests that fetch from the local feed server (see Fixtures/router.php).
 * The server runs on 127.0.0.1, so fetches from it need $allowPrivateNetworks = true.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    private static ?FeedServer $server = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$server = new FeedServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            self::$server->stop();
            self::$server = null;
        }
        parent::tearDownAfterClass();
    }

    protected static function serverUrl(string $path): string
    {
        if (self::$server === null) {
            throw new \LogicException('The feed server only runs during a test class');
        }
        return self::$server->url($path);
    }

    /**
     * @return list<ItemObject>
     */
    protected static function items(FeedObject $feed): array
    {
        if ($feed->items === null) {
            self::fail('The feed has no items: ' . ($feed->error ?? 'and no error'));
        }
        return $feed->items;
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
        return (new \ReflectionMethod(RssParser::class, $method))->invoke(null, ...$arguments);
    }
}
