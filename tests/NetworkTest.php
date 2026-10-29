<?php

declare(strict_types=1);

namespace Timvandendries\PhpRssParser\Tests;

use PHPUnit\Framework\Attributes\Group;
use Timvandendries\PhpRssParser\RssParser;

/**
 * Tests that need internet access. Excluded by default; run them with `composer test-network`.
 * example.com serves an HTML page, so a successful fetch ends in an XML parse error.
 *
 * @group network
 */
#[Group('network')]
final class NetworkTest extends \PHPUnit\Framework\TestCase
{
    private const REACHED = 'Could not parse XML from https://example.com/';

    public function testPublicHostIsFetchedWithTheSsrfCheckOn(): void
    {
        self::assertStringStartsWith(self::REACHED, (string) RssParser::getFeed('https://example.com/')->error);
    }

    public function testHostSpellingVariantsAreFetched(): void
    {
        self::assertStringStartsWith(self::REACHED, (string) RssParser::getFeed('https://EXAMPLE.com./')->error);
    }

    public function testProxyIsIgnoredWithTheSsrfCheckOn(): void
    {
        $previous = getenv('https_proxy');
        putenv('https_proxy=http://127.0.0.1:9');
        try {
            $error = (string) RssParser::getFeed('https://example.com/')->error;
        } finally {
            putenv($previous === false ? 'https_proxy' : "https_proxy={$previous}");
        }
        self::assertStringStartsWith(self::REACHED, $error, 'a direct connection, not through the dead proxy');
    }

    public function testUnresolvableHost(): void
    {
        self::assertSame('Could not resolve host no-such-host.invalid', RssParser::getFeed('http://no-such-host.invalid/')->error);
    }
}
