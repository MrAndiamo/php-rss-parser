<?php

declare(strict_types=1);

namespace Timvandendries\PhpRssParser\Tests;

use Timvandendries\PhpRssParser\objects\FeedObject;
use Timvandendries\PhpRssParser\RssParser;

final class RssParsingTest extends TestCase
{
    private static function feed(string $path): FeedObject
    {
        $feed = RssParser::getFeed(self::serverUrl($path), true);
        self::assertNull($feed->error);
        return $feed;
    }

    public function testChannelFields(): void
    {
        $url = self::serverUrl('/feeds/rss.xml');
        $feed = RssParser::getFeed($url, true);

        self::assertNull($feed->error);
        self::assertNull($feed->id);
        self::assertSame('RSS feed', $feed->title);
        self::assertSame('An RSS feed', $feed->description);
        self::assertSame('nl', $feed->language);
        self::assertSame('Wed, 01 Oct 2026 12:00:00 GMT', $feed->updated, 'lastBuildDate is used for updated');
        self::assertSame('https://example.org/', $feed->siteUrl);
        self::assertSame($url, $feed->feedUrl, 'feedUrl is the requested URL');
        self::assertCount(4, self::items($feed));
    }

    public function testItemFields(): void
    {
        $item = self::items(self::feed('/feeds/rss.xml'))[0];

        self::assertSame('Item with link', $item->title);
        self::assertSame('https://example.org/i1', $item->itemUrl);
        self::assertSame('Wed, 01 Oct 2026 10:00:00 GMT', $item->published);
        self::assertSame('https://example.org/i1.jpg', $item->imageUrl);
        self::assertSame('image/jpeg', $item->imageType);
    }

    public function testItemUrlFallsBackToPermalinkGuidOnly(): void
    {
        $items = self::items(self::feed('/feeds/rss.xml'));

        self::assertSame('https://example.org/guid', $items[1]->itemUrl, 'permalink guid');
        self::assertNull($items[2]->itemUrl, 'guid with isPermaLink="false"');
        self::assertNull($items[3]->itemUrl, 'guid that is not a URL');
    }

    public function testRss1(): void
    {
        $feed = self::feed('/feeds/rdf.xml');

        self::assertSame('RSS 1.0 feed', $feed->title);
        self::assertSame('https://example.org/', $feed->siteUrl);
        $item = self::items($feed)[0];
        self::assertSame('RDF item', $item->title);
        self::assertSame('https://example.org/a', $item->itemUrl);
    }

    public function testBomAndLeadingWhitespaceAreSkipped(): void
    {
        self::assertSame('RSS feed', self::feed('/with-whitespace/rss.xml')->title);
        self::assertSame('Atom feed', self::feed('/with-bom/atom.xml')->title);
    }

    public function testNonFeedDocumentsAreErrors(): void
    {
        $html = RssParser::getFeed(self::serverUrl('/feeds/html-page.xml'), true);
        self::assertStringContainsString('Not an RSS feed', (string) $html->error);
        self::assertStringContainsString('<html>', (string) $html->error);

        $noChannel = RssParser::getFeed(self::serverUrl('/feeds/rss-no-channel.xml'), true);
        self::assertStringContainsString('has no <channel>', (string) $noChannel->error);
    }

    public function testBrokenXmlReportsTheFatalError(): void
    {
        $feed = RssParser::getFeed(self::serverUrl('/feeds/broken.xml'), true);

        self::assertStringContainsString('Could not parse XML', (string) $feed->error);
        self::assertStringContainsString('Opening and ending tag mismatch', (string) $feed->error);
    }

    public function testRssMethodRefusesAtom(): void
    {
        $feed = RssParser::getRSSFeedByUrl(self::serverUrl('/feeds/atom.xml'), true);

        self::assertStringContainsString('Not an RSS feed', (string) $feed->error);
        self::assertStringContainsString('<feed>', (string) $feed->error);
    }
}
