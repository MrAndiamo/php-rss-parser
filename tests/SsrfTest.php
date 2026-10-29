<?php

declare(strict_types=1);

namespace Timvandendries\PhpRssParser\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Timvandendries\PhpRssParser\RssParser;

/**
 * Protection against server-side request forgery: by default, URLs may not reach private or reserved
 * addresses. These tests use IP literals and the local test server, so they need no internet access.
 */
final class SsrfTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedUrls(): array
    {
        return [
            'loopback'                => ['http://127.0.0.1/feed'],
            'localhost'               => ['http://localhost/feed'],
            'IPv6 loopback'           => ['http://[::1]/feed'],
            'IPv4-mapped loopback'    => ['http://[::ffff:127.0.0.1]/feed'],
            'cloud metadata'          => ['http://169.254.169.254/latest/meta-data/'],
            'private 10/8'            => ['http://10.0.0.1/'],
            'private 172.16/12'       => ['http://172.31.255.255/'],
            'private 192.168/16'      => ['http://192.168.1.1/'],
            'carrier-grade NAT'       => ['http://100.64.0.1/'],
            'unspecified'             => ['http://0.0.0.0/'],
            'IPv6 unique local'       => ['http://[fd00::1]/'],
            'IPv6 link-local'         => ['http://[fe80::1]/'],
            'NAT64 to metadata'       => ['http://[64:ff9b::a9fe:a9fe]/'],
            'local-use NAT64'         => ['http://[64:ff9b:1::a9fe:a9fe]/'],
            'IPv4-translated'         => ['http://[::ffff:0:a9fe:a9fe]/'],
        ];
    }

    /**
     * @dataProvider blockedUrls
     */
    #[DataProvider('blockedUrls')]
    public function testPrivateAndReservedAddressesAreRefused(string $url): void
    {
        $error = (string) RssParser::getFeed($url)->error;

        self::assertStringStartsWith('Refusing to fetch from', $error);
        self::assertStringContainsString('private or reserved address', $error);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function addresses(): array
    {
        return [
            '172.32.0.1 is just outside 172.16/12'    => ['172.32.0.1', false],
            '100.128.0.1 is just outside 100.64/10'   => ['100.128.0.1', false],
            'public IPv4'                             => ['8.8.8.8', false],
            'public IPv6'                             => ['2606:4700:4700::1111', false],
            'NAT64 to a public address'               => ['64:ff9b::808:808', false],
            'IPv4-mapped public address'              => ['::ffff:8.8.8.8', false],
            'broadcast'                               => ['255.255.255.255', true],
            'IPv4-compatible loopback'                => ['::127.0.0.1', true],
            'documentation range'                     => ['2001:db8::1', true],
            'unparsable counts as blocked'            => ['not-an-address', true],
        ];
    }

    /**
     * @dataProvider addresses
     */
    #[DataProvider('addresses')]
    public function testAddressRangeEdges(string $address, bool $blocked): void
    {
        self::assertSame($blocked, self::callPrivate('_isBlockedAddress', $address));
    }

    public function testAllowingPrivateNetworksAppliesToOneCallOnly(): void
    {
        $url = self::serverUrl('/feeds/rss.xml');

        self::assertNull(RssParser::getFeed($url, true)->error);
        self::assertStringStartsWith('Refusing to fetch from', (string) RssParser::getFeed($url)->error);
        self::assertStringStartsWith('Refusing to fetch from', (string) RssParser::getRSSFeedByUrl($url)->error);
        self::assertStringStartsWith('Refusing to fetch from', (string) RssParser::getAtomFeedByUrl($url)->error);
    }

    public function testProxyIsUsedOnlyWhenPrivateNetworksAreAllowed(): void
    {
        $url = self::serverUrl('/feeds/rss.xml');
        // Nothing listens on port 9 (discard), so a request through this proxy fails.
        $previous = getenv('http_proxy');
        putenv('http_proxy=http://127.0.0.1:9');
        try {
            $error = (string) RssParser::getFeed($url, true)->error;
        } finally {
            putenv($previous === false ? 'http_proxy' : "http_proxy={$previous}");
        }

        self::assertStringStartsWith('cURL error for', $error, 'with $allowPrivateNetworks the (dead) proxy is used');
    }

    public function testAllCheckedAddressesArePinned(): void
    {
        $pin = self::callPrivate('_pinList', ['93.184.216.34', '2606:2800:220:1::1', '93.184.216.35']);

        if (version_compare(curl_version()['version'], '7.59.0', '>=')) {
            self::assertSame('93.184.216.34,[2606:2800:220:1::1],93.184.216.35', $pin);
        } else {
            self::assertSame('93.184.216.34', $pin, 'older libcurl gets one IPv4 address');
        }
    }

    public function testConnectedAddressMustBeOneOfTheCheckedAddresses(): void
    {
        self::assertTrue(self::callPrivate('_isOneOf', '2606:2800:220:1::1', ['93.184.216.34', '2606:2800:0220:0001:0000:0000:0000:0001']));
        self::assertFalse(self::callPrivate('_isOneOf', '127.0.0.1', ['93.184.216.34']));
        self::assertFalse(self::callPrivate('_isOneOf', '', ['93.184.216.34']), 'no address reported means not checked');
    }
}
