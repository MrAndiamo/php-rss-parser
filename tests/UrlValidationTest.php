<?php

declare(strict_types=1);

namespace Timvandendries\PhpRssParser\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Timvandendries\PhpRssParser\RssParser;

/**
 * Which URLs the URL methods accept, and the form in which they are passed to cURL.
 */
final class UrlValidationTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: non-empty-string}>
     */
    public static function refusedUrls(): array
    {
        $localHint = 'URL must start with http:// or https://; use getFeedFromFile() for local files';
        return [
            'absolute path'             => ['/etc/passwd', $localHint],
            'Windows path with a space' => ['C:\\feeds\\my feed.xml', $localHint],
            'file URL'                  => ['file:///etc/passwd', $localHint],
            'no scheme'                 => ['example.com/rss', $localHint],
            'other scheme'              => ['gopher://example.com/', "Unsupported URL scheme 'gopher', only http and https are allowed"],
            'no host'                   => ['http:///feed', 'Invalid URL'],
            'backslash in authority'    => ['http://a@127.0.0.1\\@example.com/', 'Invalid URL: ambiguous host'],
            'two @ in authority'        => ['http://a@b@example.com/', 'Invalid URL: ambiguous host'],
            'space'                     => ['http://exa mple.com/', 'Invalid URL: contains spaces or control characters'],
            'control character'         => ["http://example.com/\x01", 'Invalid URL: contains spaces or control characters'],
            'NUL byte'                  => ["http://example.com/\0", 'Invalid URL: contains spaces or control characters'],
            'label starting with -'     => ['http://-bad-.com/', 'Invalid host name -bad-.com'],
            'empty label'               => ['http://a..b/', 'Invalid host name a..b'],
            'bad IPv6 address'          => ['http://[::1::2]/', 'Invalid URL'],
        ];
    }

    /**
     * @param non-empty-string $expectedError
     */
    #[DataProvider('refusedUrls')]
    public function testRefusedUrls(string $url, string $expectedError): void
    {
        self::assertStringStartsWith($expectedError, (string) RssParser::getFeed($url)->error);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function preparedUrls(): array
    {
        return [
            'host lowercased, trailing dot and fragment dropped' => [
                'http://Example.COM./Feed?x=1#top',
                ['url' => 'http://example.com/Feed?x=1', 'host' => 'example.com', 'port' => 80, 'isIp' => false],
            ],
            'credentials kept in the request URL only' => [
                'https://user:pw@example.com:8443/f?token=t&cat=2',
                ['url' => 'https://user:pw@example.com:8443/f?token=t&cat=2', 'safeUrl' => 'https://example.com:8443/f?token=REDACTED&cat=2', 'port' => 8443],
            ],
            'IPv6 literal' => [
                'http://[::FFFF:7F00:1]:8080/x',
                ['url' => 'http://[::ffff:7f00:1]:8080/x', 'host' => '::ffff:7f00:1', 'isIp' => true],
            ],
            'underscore in host name' => [
                'http://my_host.example.com/',
                ['host' => 'my_host.example.com'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('preparedUrls')]
    public function testUrlsAreRebuiltForCurl(string $url, array $expected): void
    {
        $prepared = self::prepare($url);
        foreach ($expected as $key => $value) {
            self::assertSame($value, $prepared[$key], $key);
        }
    }

    public function testInternationalizedHostNeedsIntl(): void
    {
        if (function_exists('idn_to_ascii')) {
            self::markTestSkipped('The intl extension is loaded');
        }
        self::assertSame('Internationalized host name bücher.de needs the intl extension', RssParser::getFeed('http://bücher.de/feed')->error);
    }

    public function testInternationalizedHostIsConvertedToPunycode(): void
    {
        if (!function_exists('idn_to_ascii')) {
            self::markTestSkipped('Needs the intl extension');
        }
        $prepared = self::prepare('http://Bücher.de/feed');
        self::assertSame('xn--bcher-kva.de', $prepared['host']);
        self::assertSame('http://xn--bcher-kva.de/feed', $prepared['url']);
    }

    /**
     * @return array<mixed> what _prepareUrl() returns
     */
    private static function prepare(string $url): array
    {
        $prepared = self::callPrivate('_prepareUrl', $url);
        self::assertIsArray($prepared);
        return $prepared;
    }
}
