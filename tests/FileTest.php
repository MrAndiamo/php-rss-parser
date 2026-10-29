<?php

declare(strict_types=1);

namespace Timvandendries\PhpRssParser\Tests;

use Timvandendries\PhpRssParser\RssParser;

final class FileTest extends TestCase
{
    public function testFeedTypeIsDetected(): void
    {
        $rss = RssParser::getFeedFromFile(self::fixturePath('rss.xml'));
        self::assertNull($rss->error);
        self::assertSame('RSS feed', $rss->title);
        self::assertSame(self::fixturePath('rss.xml'), $rss->feedUrl);

        self::assertSame('Atom feed', RssParser::getFeedFromFile(self::fixturePath('atom.xml'))->title);
    }

    public function testTypeSpecificMethods(): void
    {
        self::assertSame('RSS feed', RssParser::getRSSFeedFromFile(self::fixturePath('rss.xml'))->title);
        self::assertSame('Atom feed', RssParser::getAtomFeedFromFile(self::fixturePath('atom.xml'))->title);

        self::assertStringStartsWith('Not an RSS feed', (string) RssParser::getRSSFeedFromFile(self::fixturePath('atom.xml'))->error);
        self::assertStringStartsWith('Not an Atom feed', (string) RssParser::getAtomFeedFromFile(self::fixturePath('rss.xml'))->error);
    }

    public function testUrlsAreRefused(): void
    {
        $error = 'Expected a filesystem path, not a URL; use getFeed() for URLs';
        self::assertSame($error, RssParser::getFeedFromFile('https://example.com/rss.xml')->error);
        self::assertSame($error, RssParser::getFeedFromFile('file:///etc/passwd')->error);
    }

    public function testPathsContainingSchemeLikeTextAreStillPaths(): void
    {
        $path = '/var/cache/feeds/http://example.com/rss.xml';
        self::assertSame("Could not read file {$path}", RssParser::getFeedFromFile($path)->error);
    }

    public function testMissingAndInvalidPaths(): void
    {
        $missing = self::fixturePath('missing.xml');
        self::assertSame("Could not read file {$missing}", RssParser::getFeedFromFile($missing)->error);
        self::assertSame('File path contains a NUL byte', RssParser::getFeedFromFile("feed\0.xml")->error);
    }

    public function testFileSizeIsLimited(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rss-parser-test');
        try {
            file_put_contents($path, '<rss><channel><title>' . str_repeat('a', RssParser::MAX_RESPONSE_SIZE) . '</title></channel></rss>');
            self::assertSame(
                "File {$path} is larger than " . RssParser::MAX_RESPONSE_SIZE . ' bytes',
                RssParser::getFeedFromFile($path)->error
            );
        } finally {
            unlink($path);
        }
    }
}
