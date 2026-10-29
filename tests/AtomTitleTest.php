<?php

declare(strict_types=1);

namespace Timvandendries\PhpRssParser\Tests;

use Timvandendries\PhpRssParser\RssParser;

/**
 * Atom titles are always plain text, whatever markup the feed uses for them.
 */
final class AtomTitleTest extends TestCase
{
    /** @var string[] */
    private static array $titles = [];

    protected function setUp(): void
    {
        if (self::$titles === []) {
            $feed = RssParser::getFeed(self::serverUrl('/feeds/atom-titles.xml'), true);
            self::assertNull($feed->error);
            self::$titles = array_merge([$feed->title], array_map(fn($item) => $item->title, $feed->items));
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$titles = [];
        parent::tearDownAfterClass();
    }

    public function testHtmlTitleKeepsOnlyText(): void
    {
        self::assertSame('Feed & Co', self::$titles[0], 'markup and script contents are dropped');
    }

    public function testXhtmlTitleKeepsOnlyTextAndCollapsesWhitespace(): void
    {
        self::assertSame('My Post', self::$titles[1]);
    }

    public function testTextTitleIsLeftAsText(): void
    {
        self::assertSame('<script>alert(1)</script>', self::$titles[2], 'plain text; escaping is up to the caller');
    }

    public function testHtmlEntitiesAndUnicode(): void
    {
        self::assertSame('café 日本', self::$titles[3]);
    }

    public function testBlockElementsAndBreaksSeparateWords(): void
    {
        self::assertSame('Breaking News', self::$titles[4]);
        self::assertSame('Part 1 Part 2', self::$titles[5]);
    }

    public function testScriptAndStyleAreDroppedFromXhtmlToo(): void
    {
        self::assertSame('Hello', self::$titles[6]);
    }

    public function testNonBreakingSpacesAreCollapsedAndTrimmed(): void
    {
        self::assertSame('Title Text', self::$titles[7]);
    }
}
