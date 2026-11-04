<?php

declare(strict_types=1);

namespace Timvandendries\PhpRssParser\Tests;

use Timvandendries\PhpRssParser\RssParser;

final class ConditionalGetTest extends TestCase
{
    private const ETAG = '"v1"';
    private const LAST_MODIFIED = 'Wed, 01 Jan 2025 00:00:00 GMT';

    public function testAFetchReturnsTheValidatorsAndResponseDetails(): void
    {
        $feed = RssParser::getFeedIfChanged(self::serverUrl('/conditional'), null, null, true);

        self::assertNull($feed->error);
        self::assertSame('RSS feed', $feed->title);
        self::assertFalse($feed->notModified);
        self::assertSame(200, $feed->status);
        self::assertSame(self::ETAG, $feed->etag);
        self::assertSame(self::LAST_MODIFIED, $feed->lastModified);
        self::assertSame(strlen((string) file_get_contents(self::fixturePath('rss.xml'))), $feed->responseSize);
    }

    public function testGetFeedReturnsTheValidatorsToo(): void
    {
        $feed = RssParser::getFeed(self::serverUrl('/conditional'), true);

        self::assertSame('RSS feed', $feed->title);
        self::assertSame(self::ETAG, $feed->etag);
        self::assertSame(self::LAST_MODIFIED, $feed->lastModified);
    }

    public function testAMatchingEtagGivesNotModified(): void
    {
        $feed = RssParser::getFeedIfChanged(self::serverUrl('/conditional'), self::ETAG, null, true);

        self::assertTrue($feed->notModified);
        self::assertFalse($feed->hasError());
        self::assertNull($feed->title);
        self::assertNull($feed->items);
        self::assertSame(304, $feed->status);
        self::assertSame(0, $feed->responseSize);
        // The validators stay usable for the next fetch.
        self::assertSame(self::ETAG, $feed->etag);
        self::assertSame(self::LAST_MODIFIED, $feed->lastModified);
    }

    public function testAMatchingLastModifiedGivesNotModified(): void
    {
        $feed = RssParser::getFeedIfChanged(self::serverUrl('/conditional'), null, self::LAST_MODIFIED, true);

        self::assertTrue($feed->notModified);
        self::assertSame(304, $feed->status);
    }

    public function testAChangedFeedIsDownloaded(): void
    {
        $feed = RssParser::getFeedIfChanged(self::serverUrl('/conditional'), '"old"', 'Mon, 01 Jan 2024 00:00:00 GMT', true);

        self::assertFalse($feed->notModified);
        self::assertSame('RSS feed', $feed->title);
        self::assertSame(self::ETAG, $feed->etag);
    }

    public function testValidatorsAreSentAfterARedirect(): void
    {
        $feed = RssParser::getFeedIfChanged(self::serverUrl('/redirect?to=/conditional'), self::ETAG, null, true);

        self::assertTrue($feed->notModified);
    }

    public function testValidatorsThatCouldAddHeadersAreNotSent(): void
    {
        $sent = RssParser::getFeedIfChanged(self::serverUrl('/if-none-match'), '"abc"', null, true);
        self::assertSame('"abc"', $sent->title);

        $refused = RssParser::getFeedIfChanged(self::serverUrl('/if-none-match'), "\"abc\"\r\nX-Injected: 1", null, true);
        self::assertSame('(none)', $refused->title);

        $tooLong = RssParser::getFeedIfChanged(self::serverUrl('/if-none-match'), str_repeat('a', 1025), null, true);
        self::assertSame('(none)', $tooLong->title);
    }

    public function testNotModifiedWithoutValidatorsIsAnError(): void
    {
        $feed = RssParser::getFeed(self::serverUrl('/status/304'), true);

        self::assertFalse($feed->notModified);
        self::assertStringStartsWith('HTTP 304 for', (string) $feed->error);
        self::assertSame(304, $feed->status);
    }

    public function testHttpErrorsKeepTheirStatus(): void
    {
        self::assertSame(404, RssParser::getFeed(self::serverUrl('/status/404'), true)->status);
    }

    public function testRetryAfterInSeconds(): void
    {
        $feed = RssParser::getFeed(self::serverUrl('/rate-limited?after=120'), true);

        self::assertStringStartsWith('HTTP 429 for', (string) $feed->error);
        self::assertSame(429, $feed->status);
        self::assertSame(120, $feed->retryAfter);
    }

    public function testRetryAfterAsADate(): void
    {
        $date = gmdate('D, d M Y H:i:s \G\M\T', time() + 600);
        $retryAfter = RssParser::getFeed(self::serverUrl('/rate-limited?after=' . rawurlencode($date)), true)->retryAfter;

        self::assertNotNull($retryAfter);
        self::assertGreaterThanOrEqual(590, $retryAfter);
        self::assertLessThanOrEqual(600, $retryAfter);

        $past = gmdate('D, d M Y H:i:s \G\M\T', time() - 600);
        self::assertSame(0, RssParser::getFeed(self::serverUrl('/rate-limited?after=' . rawurlencode($past)), true)->retryAfter);
    }

    public function testAnUnreadableRetryAfterIsIgnored(): void
    {
        self::assertNull(RssParser::getFeed(self::serverUrl('/rate-limited?after=soon'), true)->retryAfter);
    }

    public function testNoResponseMeansNoStatus(): void
    {
        $feed = RssParser::getFeed('http://feeds.invalid/rss.xml');

        self::assertTrue($feed->hasError());
        self::assertNull($feed->status);
        self::assertNull($feed->etag);
    }

    public function testFilesHaveNoResponseDetails(): void
    {
        $feed = RssParser::getFeedFromFile(self::fixturePath('rss.xml'));

        self::assertSame('RSS feed', $feed->title);
        self::assertNull($feed->status);
        self::assertFalse($feed->notModified);
        self::assertSame(0, $feed->responseSize);
    }
}
