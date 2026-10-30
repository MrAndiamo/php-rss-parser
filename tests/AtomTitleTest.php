<?php

declare(strict_types=1);

namespace Timvandendries\PhpRssParser\Tests;

use Timvandendries\PhpRssParser\RssParser;

/**
 * Atom titles are always plain text, whatever markup the feed uses for them.
 */
final class AtomTitleTest extends TestCase
{
    private static ?string $feedTitle = null;

    /** @var array<string, string|null> item titles by entry id */
    private static array $titles = [];

    protected function setUp(): void
    {
        if (self::$titles === []) {
            $feed = RssParser::getFeed(self::serverUrl('/feeds/atom-titles.xml'), true);
            self::assertNull($feed->error);
            // Built locally and stored only when complete: a failing duplicate check must not leave a
            // half-filled cache that later tests would then use without reloading.
            $titles = [];
            foreach (self::items($feed) as $item) {
                self::assertArrayNotHasKey($item->id, $titles, "atom-titles.xml has id {$item->id} twice");
                $titles[$item->id] = $item->title;
            }
            self::$feedTitle = $feed->title;
            self::$titles = $titles;
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$feedTitle = null;
        self::$titles = [];
        parent::tearDownAfterClass();
    }

    /**
     * The title of the fixture entry with this <id>, so tests don't depend on the entries' order.
     */
    private static function title(string $id): ?string
    {
        // array_key_exists, not isset: an entry whose title came out null should fail on the title
        // itself, not be reported as a missing entry.
        if (!array_key_exists($id, self::$titles)) {
            self::fail("atom-titles.xml has no entry with id {$id}");
        }
        return self::$titles[$id];
    }

    public function testHtmlTitleKeepsOnlyText(): void
    {
        self::assertSame('Feed & Co', self::$feedTitle, 'markup and script contents are dropped');
    }

    public function testXhtmlTitleKeepsOnlyTextAndCollapsesWhitespace(): void
    {
        self::assertSame('My Post', self::title('xhtml'));
    }

    public function testTextTitleIsLeftAsText(): void
    {
        self::assertSame('<script>alert(1)</script>', self::title('text'), 'plain text; escaping is up to the caller');
    }

    public function testHtmlEntitiesAndUnicode(): void
    {
        self::assertSame('café 日本', self::title('entities'));
    }

    public function testBlockElementsAndBreaksSeparateWords(): void
    {
        self::assertSame('Breaking News', self::title('br'));
        self::assertSame('Part 1 Part 2', self::title('paragraphs'));
    }

    public function testScriptAndStyleAreDroppedFromXhtmlToo(): void
    {
        self::assertSame('Hello', self::title('xhtml-script-style'));
    }

    public function testPrefixedHtmlElementsAreInlineUnknownElements(): void
    {
        // loadHTML() keeps the "h:" and "o:" prefixes in the element name, and a browser shows such
        // unknown elements inline, so they don't separate words.
        self::assertSame('AB', self::title('prefixed-html'));
    }

    public function testDeclaredXhtmlPrefixIsARealBlock(): void
    {
        // Here "h:" is declared as the XHTML namespace, so <h:p> is a real <p> (and is one in content).
        self::assertSame('A B', self::title('xhtml-declared-prefix-block'));
    }

    public function testPrefixedStyleAndScriptInHtmlKeepTheirText(): void
    {
        // <foo:style> is no <style>, so a browser shows its text.
        self::assertSame('Price10 EUR!', self::title('html-prefixed-style'));
    }

    public function testDeclaredForeignStyleKeepsItsText(): void
    {
        // Its local name is "style", but in namespace urn:x it is no HTML style element.
        self::assertSame('Price10 EUR', self::title('xhtml-declared-foreign-style'));
    }

    public function testSvgStyleIsHiddenLikeInABrowser(): void
    {
        self::assertSame('T', self::title('xhtml-svg-style'));
    }

    public function testForeignNamespaceElementIsInlineNotABlock(): void
    {
        // Content unwraps <x:p xmlns:x="urn:x"> to inline text, so the title must not split there.
        self::assertSame('ABC', self::title('xhtml-foreign-block'));
    }

    public function testWordParagraphIsInlineInHtmlAndXhtmlAlike(): void
    {
        // Word's <o:p> stays <o:p> in content HTML, which a browser shows inline: "ABC", like the title.
        self::assertSame('ABC', self::title('html-word-paragraph'));
        self::assertSame('ABC', self::title('xhtml-word-paragraph'), 'XML keeps the undeclared "o:" prefix too');
    }

    public function testNamesWithSeveralColonsAreNoBlocks(): void
    {
        self::assertSame('ABC', self::title('html-two-colons'), '<a:b:p> is not taken for <p>');
    }

    public function testUndeclaredPrefixInXhtmlIsAnUnknownElement(): void
    {
        // In XML, <foo:style> is not <style>: its text stays, as it does in the HTML from _appendAsHtml().
        self::assertSame('Price10 EUR', self::title('xhtml-undeclared-prefix'));
    }

    public function testNonBreakingSpacesAreCollapsedAndTrimmed(): void
    {
        self::assertSame('Title Text', self::title('nbsp'));
    }
}
