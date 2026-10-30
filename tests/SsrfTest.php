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

    /**
     * @return array<string, array{0: list<string>, 1: string, 2: string}>
     */
    public static function pinLists(): array
    {
        $mixed = ['93.184.216.34', '2606:2800:220:1::1', '93.184.216.35'];
        $ipv6Only = ['2606:2800:220:1::1', '2606:2800:220:1::2'];
        return [
            'current libcurl pins all addresses'    => [$mixed, '8.18.0', '93.184.216.34,[2606:2800:220:1::1],93.184.216.35'],
            '7.59 pins all addresses'               => [$mixed, '7.59.0', '93.184.216.34,[2606:2800:220:1::1],93.184.216.35'],
            'before 7.59: one IPv4 address'         => [$mixed, '7.58.0', '93.184.216.34'],
            'before 7.59, IPv6 only: one address'   => [$ipv6Only, '7.57.0', '[2606:2800:220:1::1]'],
            'dev build counts as older: one IPv4'   => [$mixed, '7.59.0-DEV', '93.184.216.34'],
            'daily snapshot counts as older'        => [$mixed, '7.59.0-20180305', '93.184.216.34'],
            'later dev build pins all addresses'    => [$mixed, '7.60.0-DEV', '93.184.216.34,[2606:2800:220:1::1],93.184.216.35'],
            'release without patch number: 7.59'    => [$mixed, '7.59', '93.184.216.34,[2606:2800:220:1::1],93.184.216.35'],
            'release without patch number: 7.58'    => [$mixed, '7.58', '93.184.216.34'],
        ];
    }

    /**
     * @param list<string> $addresses
     */
    #[DataProvider('pinLists')]
    public function testPinnedAddressesDependOnTheLibcurlVersion(array $addresses, string $version, string $expected): void
    {
        self::assertSame($expected, self::callPrivate('_pinList', $addresses, $version));
    }

    public function testErrorsShowTheVersionLibcurlReported(): void
    {
        // "7.56" is compared as 7.56.0, but the message shows what libcurl said.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("libcurl 7.56 can't pin IPv6 addresses");
        self::callPrivate('_pinList', ['2606:2800:220:1::1'], '7.56');
    }

    public function testIpv6OnlyHostIsRefusedOnA757DevBuild(): void
    {
        // A dev snapshot of 7.57 may predate bracketed IPv6 pins, so it fails safe like 7.56.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("libcurl 7.57.0-DEV can't pin IPv6 addresses");
        self::callPrivate('_pinList', ['2606:2800:220:1::1'], '7.57.0-DEV');
    }

    public function testIpv6OnlyHostCantBePinnedBeforeLibcurl757(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("libcurl 7.56.0 can't pin IPv6 addresses");
        self::callPrivate('_pinList', ['2606:2800:220:1::1'], '7.56.0');
    }

    /**
     * The installed libcurl's version and features, read independently of the code under test.
     *
     * @return array{version: string, features: int}
     */
    private static function installedCurl(): array
    {
        $curl = curl_version();
        self::assertIsArray($curl);
        $version = $curl['version'] ?? null;
        $features = $curl['features'] ?? null;
        self::assertIsString($version);
        self::assertIsInt($features);
        return ['version' => $version, 'features' => $features];
    }

    /**
     * @param list<string> $checkedAddresses
     * @return array<mixed> the cURL options, keyed by CURLOPT_* constant
     */
    private static function connectionOptions(bool $isIp, bool $allowPrivateNetworks, array $checkedAddresses): array
    {
        $target = [
            'url' => 'https://example.com/', 'safeUrl' => 'https://example.com/',
            'host' => $isIp ? '93.184.216.34' : 'example.com', 'port' => 443, 'isIp' => $isIp,
        ];
        $options = self::callPrivate('_connectionOptions', $target, $allowPrivateNetworks, $checkedAddresses, 5000);
        self::assertIsArray($options);
        return $options;
    }

    public function testRequestsPinTheCheckedAddressesForTheInstalledLibcurl(): void
    {
        $addresses = ['93.184.216.34', '2606:2800:220:1::1'];
        $options = self::connectionOptions(false, false, $addresses);

        // Checks the wiring: one entry for host and port, holding the pin list for the installed
        // libcurl, whose version is read here independently. Which list belongs to which version is
        // tested by testPinnedAddressesDependOnTheLibcurlVersion, without copying the threshold here.
        $pinList = self::callPrivate('_pinList', $addresses, self::installedCurl()['version']);
        self::assertIsString($pinList);
        self::assertSame(["example.com:443:{$pinList}"], $options[CURLOPT_RESOLVE] ?? null);
        self::assertSame('', $options[CURLOPT_PROXY] ?? null, 'no proxy with the address check on');
        self::assertTrue($options[CURLOPT_NOSIGNAL] ?? null);
        self::assertSame(5000, $options[CURLOPT_TIMEOUT_MS] ?? null);
        self::assertSame(5000, $options[CURLOPT_CONNECTTIMEOUT_MS] ?? null, 'never longer than the time left');
    }

    public function testIpAddressTargetsNeedNoPin(): void
    {
        $options = self::connectionOptions(true, false, ['93.184.216.34']);

        self::assertArrayNotHasKey(CURLOPT_RESOLVE, $options);
        self::assertSame('', $options[CURLOPT_PROXY] ?? null);
    }

    public function testAllowingPrivateNetworksLeavesProxyAndDnsToCurl(): void
    {
        $options = self::connectionOptions(false, true, []);

        self::assertArrayNotHasKey(CURLOPT_PROXY, $options);
        self::assertArrayNotHasKey(CURLOPT_RESOLVE, $options);
        if ((self::installedCurl()['features'] & CURL_VERSION_ASYNCHDNS) !== 0) {
            self::assertTrue($options[CURLOPT_NOSIGNAL] ?? null);
            self::assertSame(5000, $options[CURLOPT_TIMEOUT_MS] ?? null);
        } else {
            self::assertArrayNotHasKey(CURLOPT_NOSIGNAL, $options, 'signals time out cURL\'s own DNS lookups');
            self::assertSame(5, $options[CURLOPT_TIMEOUT] ?? null);
        }
    }

    public function testAddressCheckWithoutCheckedAddressesIsABug(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('needs the checked addresses');
        self::connectionOptions(false, false, []);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function noTimeLeft(): array
    {
        return ['zero' => [0], 'negative' => [-1]];
    }

    #[DataProvider('noTimeLeft')]
    public function testNoTimeLeftIsATimeoutNotARequestWithoutTimeout(int $remainingMs): void
    {
        // cURL would read a 0 timeout as "no timeout at all", so no options may be built.
        $target = ['url' => 'https://example.com/', 'safeUrl' => 'https://example.com/', 'host' => 'example.com', 'port' => 443, 'isIp' => false];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Timed out after ' . RssParser::TIMEOUT . ' seconds fetching https://example.com/');
        self::callPrivate('_connectionOptions', $target, true, [], $remainingMs);
    }

    public function testUnreadableLibcurlVersionIsRefusedWhenPinning(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("libcurl reports a version this library can't read: 'libcurl/8.x'");
        self::callPrivate('_pinList', ['93.184.216.34'], 'libcurl/8.x');
    }

    public function testConnectedAddressMustBeOneOfTheCheckedAddresses(): void
    {
        self::assertTrue(self::callPrivate('_isOneOf', '2606:2800:220:1::1', ['93.184.216.34', '2606:2800:0220:0001:0000:0000:0000:0001']));
        self::assertFalse(self::callPrivate('_isOneOf', '127.0.0.1', ['93.184.216.34']));
        self::assertFalse(self::callPrivate('_isOneOf', '', ['93.184.216.34']), 'no address reported means not checked');
    }
}
