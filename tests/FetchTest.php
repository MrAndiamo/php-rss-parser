<?php

declare(strict_types=1);

namespace Timvandendries\PhpRssParser\Tests;

use Timvandendries\PhpRssParser\RssParser;

final class FetchTest extends TestCase
{
    private static function error(string $path): string
    {
        return (string) RssParser::getFeed(self::serverUrl($path), true)->error;
    }

    public function testHttpErrorStatus(): void
    {
        self::assertSame('HTTP 404 for ' . self::serverUrl('/status/404'), self::error('/status/404'));
        self::assertStringStartsWith('HTTP 500 for', self::error('/status/500'));
    }

    public function testRedirectIsFollowedAndFeedUrlStaysTheRequestedUrl(): void
    {
        $url = self::serverUrl('/redirect?to=/feeds/rss.xml');
        $feed = RssParser::getFeed($url, true);

        self::assertNull($feed->error);
        self::assertSame('RSS feed', $feed->title);
        self::assertSame($url, $feed->feedUrl);
    }

    public function testRedirectWithoutLocationIsAnError(): void
    {
        self::assertStringStartsWith('HTTP 302 for', self::error('/redirect-no-location'));
    }

    public function testRedirectLoopStops(): void
    {
        self::assertStringStartsWith('Too many redirects', self::error('/redirect-loop'));
    }

    public function testRedirectTargetsGetTheSameChecksAndNameTheStartUrl(): void
    {
        $start = self::serverUrl('/redirect?to=gopher://127.0.0.1/');
        $error = self::error('/redirect?to=gopher://127.0.0.1/');

        self::assertStringContainsString("Unsupported URL scheme 'gopher'", $error);
        self::assertStringContainsString("(after a redirect from {$start})", $error);

        self::assertStringContainsString('use getFeedFromFile()', self::error('/redirect?to=file:///etc/passwd'));
    }

    public function testTooLargeResponseIsRefused(): void
    {
        self::assertStringContainsString('is larger than ' . RssParser::MAX_RESPONSE_SIZE . ' bytes', self::error('/too-large'));
    }

    public function testDecompressedSizeIsLimitedToo(): void
    {
        self::assertStringContainsString('is larger than ' . RssParser::MAX_RESPONSE_SIZE . ' bytes', self::error('/gzip-bomb'));
    }

    public function testCompressedResponsesAreDecoded(): void
    {
        self::assertSame('Compressed', RssParser::getFeed(self::serverUrl('/gzip'), true)->title);
    }

    public function testUserAgentIsSent(): void
    {
        $expected = ini_get('user_agent') ?: RssParser::USER_AGENT;
        self::assertSame($expected, RssParser::getFeed(self::serverUrl('/user-agent'), true)->title);
    }

    public function testSecretsAreHiddenInErrorsButOrdinaryParametersAreNot(): void
    {
        $url = str_replace('http://', 'http://user:password@', self::serverUrl('/status/404'))
            . '?cat=5&author=jane&Api-Key=a&client_secret=b&X-Amz-Signature=c&apiToken=d&sessionid=e&PHPSESSID=f';
        $error = (string) RssParser::getFeed($url, true)->error;

        self::assertStringNotContainsString('password', $error);
        self::assertStringNotContainsString('user:', $error);
        self::assertStringContainsString('cat=5&author=jane', $error);
        self::assertStringContainsString(
            'Api-Key=REDACTED&client_secret=REDACTED&X-Amz-Signature=REDACTED&apiToken=REDACTED&sessionid=REDACTED&PHPSESSID=REDACTED',
            $error
        );
    }

    public function testTimeLimitCoversTheWholeFetch(): void
    {
        $target = self::callPrivate('_prepareUrl', self::serverUrl('/feeds/rss.xml'));

        try {
            self::callPrivate('_request', $target, true, microtime(true) - 1);
            self::fail('A request after the deadline should not be made');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('Timed out after ' . RssParser::TIMEOUT . ' seconds', $e->getMessage());
        }

        $result = self::callPrivate('_request', $target, true, microtime(true) + 5);
        self::assertIsArray($result);
        self::assertSame(200, $result[0] ?? null);
    }
}
