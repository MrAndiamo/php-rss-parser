<?php

declare(strict_types=1);

namespace Timvandendries\PhpRssParser\Tests;

use Timvandendries\PhpRssParser\objects\FeedObject;
use Timvandendries\PhpRssParser\RssParser;

final class AtomParsingTest extends TestCase
{
    private static function feed(string $path): FeedObject
    {
        $feed = RssParser::getFeed(self::serverUrl($path), true);
        self::assertNull($feed->error);
        return $feed;
    }

    public function testFeedFields(): void
    {
        $feed = self::feed('/feeds/atom.xml');

        self::assertSame('urn:feed:atom', $feed->id);
        self::assertSame('Atom feed', $feed->title);
        self::assertSame('2026-10-01T12:00:00Z', $feed->updated);
        self::assertSame('https://example.org/', $feed->siteUrl, 'siteUrl is the link without rel (alternate)');
        self::assertSame('https://example.org/atom.xml', $feed->feedUrl, 'feedUrl is the rel="self" link');
        self::assertFalse($feed->hasError());
        self::assertCount(4, self::items($feed));
    }

    public function testFeedUrlFallsBackToRequestedUrlWithoutSelfLink(): void
    {
        $url = self::serverUrl('/feeds/atom-no-namespace.xml');
        self::assertSame($url, RssParser::getFeed($url, true)->feedUrl);
    }

    public function testEntryFields(): void
    {
        $item = self::items(self::feed('/feeds/atom.xml'))[0];

        self::assertSame('urn:entry:1', $item->id);
        self::assertSame('Entry with xhtml content', $item->title);
        self::assertSame('Plain summary', $item->description);
        self::assertSame('<p>hi &amp; <b>bye</b></p>', $item->content);
        self::assertSame('Ann', $item->authorName);
        self::assertSame('ann@example.org', $item->authorEmail);
        self::assertSame('2026-10-01T10:00:00Z', $item->updated);
        self::assertSame('2026-09-30T10:00:00Z', $item->published);
        self::assertSame('https://example.org/1', $item->itemUrl);
    }

    public function testImageComesOnlyFromImageEnclosures(): void
    {
        $items = self::items(self::feed('/feeds/atom.xml'));

        self::assertNull($items[0]->imageUrl, 'a rel="replies" link is not an image');
        self::assertNull($items[0]->imageType);

        self::assertSame('https://example.org/2.jpg', $items[1]->imageUrl);
        self::assertSame('image/jpeg', $items[1]->imageType);
        self::assertSame('Picture', $items[1]->imageTitle);
        self::assertSame('https://example.org/2', $items[1]->itemUrl, 'itemUrl is the alternate link, not the first');

        self::assertSame('https://example.org/photo.JPG?w=1', $items[2]->imageUrl, 'untyped enclosure with an image extension');
        self::assertSame('', $items[2]->imageType);
    }

    public function testItemUrlIsNullWithoutAlternateLink(): void
    {
        $items = self::items(self::feed('/feeds/atom.xml'));

        self::assertNull($items[2]->itemUrl, 'only enclosures');
        self::assertNull($items[3]->itemUrl, 'only rel="related"');
    }

    public function testPrefixedAtomAndXhtmlContentBecomesNamespaceFreeHtml(): void
    {
        $feed = self::feed('/feeds/atom-prefixed.xml');
        $item = self::items($feed)[0];

        self::assertSame('Prefixed feed', $feed->title);
        self::assertSame('Prefixed entry', $item->title);
        self::assertSame('Bob', $item->authorName);
        self::assertSame('https://example.org/p', $item->itemUrl);
        self::assertSame(
            '<p class="a" xml:lang="nl">é &amp; x<br></p><p></p><p><svg><circle r="1"></circle></svg></p>a&lt;b<!-- note -->',
            $item->content
        );
    }

    public function testAtom03(): void
    {
        $feed = self::feed('/feeds/atom03.xml');
        [$inline, $encoded] = self::items($feed);

        self::assertSame('Atom 0.3 feed', $feed->title);
        self::assertSame('2004-01-01T00:00:00Z', $feed->updated, '<modified> is used for updated');
        self::assertSame('https://old.example.org/', $feed->siteUrl);

        self::assertSame('2003-12-31T00:00:00Z', $inline->published, '<issued> is used for published');
        self::assertSame('<div><p>Hello</p></div>', $inline->content, 'mode="xml" is the default');
        self::assertSame('<b>bold</b> x', $inline->description);

        self::assertSame('Base 64 title', $encoded->title);
        self::assertSame('a & b', $encoded->content, 'plain text in the default mode stays text');
        self::assertSame('hello', $encoded->description);
    }

    public function testFeedWithoutNamespace(): void
    {
        $feed = self::feed('/feeds/atom-no-namespace.xml');

        self::assertSame('No namespace', $feed->title);
        self::assertSame('https://example.org/n1', self::items($feed)[0]->itemUrl);
    }

    public function testFeedRootInOtherNamespaceIsRefused(): void
    {
        $feed = RssParser::getFeed(self::serverUrl('/feeds/feed-other-namespace.xml'), true);

        self::assertTrue($feed->hasError());
        self::assertStringContainsString('has a <feed> root in namespace urn:other', (string) $feed->error);
    }

    public function testElementsWithUndeclaredPrefixesKeepTheirNames(): void
    {
        $feed = RssParser::getFeedFromFile(self::fixturePath('atom-undeclared-prefix.xml'));

        self::assertNull($feed->error, '"1a" after the colon is no valid element name on its own');
        self::assertSame('a<x:1a>b</x:1a><o:p>c</o:p>', self::items($feed)[0]->content, 'not renamed to <1a> and <p>');
    }

    public function testForeignNamespaceElementsDontBecomeLiveHtml(): void
    {
        $feed = RssParser::getFeedFromFile(self::fixturePath('atom-foreign-namespace.xml'));

        self::assertNull($feed->error);
        // <foo:style> and <x:script> have the local names "style" and "script", but come from other
        // namespaces: only their text is kept. SVG is still recreated as inline <svg>.
        self::assertSame('Price10 EURalert(1)<svg><circle r="1"></circle></svg>', self::items($feed)[0]->content);
    }

    public function testEmptyForeignElementAddsNothing(): void
    {
        // An unwrapped empty foreign element must add no node at all; appending an empty
        // DocumentFragment instead would raise a PHP warning (fatal under failOnWarning).
        $feed = RssParser::getFeedFromFile(self::fixturePath('atom-foreign-namespace.xml'));

        self::assertSame('bc', self::items($feed)[1]->content);
    }

    public function testAtomNamespaceIsNotHtmlInAtom10Content(): void
    {
        // Only Atom 0.3 inline content inherits its Atom namespace as HTML; in Atom 1.0 xhtml content an
        // Atom-namespaced <a:script> is foreign and must not become a live <script>.
        $feed = RssParser::getFeedFromFile(self::fixturePath('atom-foreign-namespace.xml'));

        self::assertSame('Aalert(1)', self::items($feed)[2]->content);
    }

    public function testSvgAndMathmlOutsideTheirRootAreForeign(): void
    {
        // A loose SVG <s:script> or MathML <m:style> would become a live HTML <script> or <style>; only
        // inside <svg> or <math> (through SVG or MathML ancestors only) do they keep their name.
        $feed = RssParser::getFeedFromFile(self::fixturePath('atom-foreign-namespace.xml'));

        self::assertSame(
            'Aalert(1)body{}x()<svg><g><script>y()</script></g></svg>',
            self::items($feed)[3]->content,
        );
    }

    public function testTextConstructThatIsNotAnElementIsABug(): void
    {
        $xml = simplexml_load_string('<title type="html">x</title>');
        self::assertNotFalse($xml);
        $attribute = $xml->attributes()['type'] ?? null;
        self::assertNotNull($attribute, 'an attribute node, which dom_import_simplexml() turns into a DOMAttr');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('_atomTextParts() needs an element, got DOMAttr');
        self::callPrivate('_atomTextParts', $attribute, '');
    }

    public function testAtomMethodRefusesRss(): void
    {
        $feed = RssParser::getAtomFeedByUrl(self::serverUrl('/feeds/rss.xml'), true);

        self::assertStringContainsString('Not an Atom feed', (string) $feed->error);
        self::assertStringContainsString('<rss>', (string) $feed->error);
    }
}
