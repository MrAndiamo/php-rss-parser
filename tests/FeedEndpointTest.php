<?php

declare(strict_types=1);

namespace Timvandendries\PhpRssParser\Tests;

use Timvandendries\PhpRssParser\FeedEndpoint;

final class FeedEndpointTest extends TestCase
{
    private string $cacheDir;
    private string $errorLog;
    private string|false $previousErrorLog;
    /** @var list<string> */
    private array $temporaryFeeds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-rss-parser-test-' . bin2hex(random_bytes(6));
        $this->errorLog = $this->cacheDir . '.log';
        // Keeps the endpoint's error_log() lines out of the test output, and lets tests read them.
        $this->previousErrorLog = ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        foreach ([...$this->temporaryFeeds, ...(glob($this->cacheDir . DIRECTORY_SEPARATOR . '*') ?: []), $this->errorLog] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->cacheDir)) {
            rmdir($this->cacheDir);
        }
        parent::tearDown();
    }

    /**
     * An endpoint for one feed of the local feed server, which runs on 127.0.0.1.
     */
    private function endpoint(string $feedUrl, int $cacheTtl = FeedEndpoint::DEFAULT_CACHE_TTL, int $maxItems = FeedEndpoint::DEFAULT_MAX_ITEMS): FeedEndpoint
    {
        return new FeedEndpoint([$feedUrl], $this->cacheDir, $cacheTtl, $maxItems, allowPrivateNetworks: true);
    }

    /**
     * A copy of rss.xml on the feed server that the test can take away again.
     *
     * @return array{string, string} [URL, file]
     */
    private function temporaryFeed(): array
    {
        $name = 'temp-' . bin2hex(random_bytes(6)) . '.xml';
        $file = self::fixturePath($name);
        copy(self::fixturePath('rss.xml'), $file);
        $this->temporaryFeeds[] = $file;
        return [self::serverUrl('/feeds/' . $name), $file];
    }

    /**
     * @param array{status: int, headers: array<string, string>, body: string} $response
     * @return array<mixed>
     */
    private static function json(array $response): array
    {
        self::assertSame('application/json; charset=utf-8', $response['headers']['Content-Type']);
        $data = json_decode($response['body'], true);
        self::assertIsArray($data);
        return $data;
    }

    /**
     * @param array{status: int, headers: array<string, string>, body: string} $response
     * @return list<string>
     */
    private static function titles(array $response): array
    {
        $items = self::json($response)['items'] ?? null;
        self::assertIsArray($items);
        return array_map(function (mixed $item): string {
            self::assertIsArray($item);
            self::assertIsString($item['title']);
            return $item['title'];
        }, array_values($items));
    }

    private function log(): string
    {
        return is_file($this->errorLog) ? (string) file_get_contents($this->errorLog) : '';
    }

    public function testServesWhatABlockShowsOfTheFeed(): void
    {
        $url = self::serverUrl('/feeds/rss.xml');
        $response = $this->endpoint($url)->respond(['feed' => $url]);

        self::assertSame(200, $response['status']);
        self::assertSame([
            'title'   => 'RSS feed',
            'siteUrl' => 'https://example.org/',
            'items'   => [
                // The feed calls 1 October 2026 a Wednesday; the date counts, not the day name.
                ['title' => 'Item with link', 'itemUrl' => 'https://example.org/i1', 'published' => '2026-10-01T10:00:00+00:00', 'updated' => null],
                ['title' => 'Item with permalink guid', 'itemUrl' => 'https://example.org/guid', 'published' => null, 'updated' => null],
                ['title' => 'Item with guid marked not permalink', 'itemUrl' => null, 'published' => null, 'updated' => null],
                ['title' => 'Item with plain id guid', 'itemUrl' => null, 'published' => null, 'updated' => null],
            ],
        ], self::json($response));
    }

    public function testLinksAreHttpOnlyAndDatesIso8601(): void
    {
        $url = self::serverUrl('/feeds/block-links.xml');
        $data = self::json($this->endpoint($url)->respond(['feed' => $url]));

        self::assertNull($data['siteUrl'], 'javascript: site link');
        self::assertSame([
            ['title' => 'Script link', 'itemUrl' => null, 'published' => null, 'updated' => null],
            ['title' => 'Relative link', 'itemUrl' => null, 'published' => '2026-10-01T10:00:00+02:00', 'updated' => null],
            ['title' => 'Data link', 'itemUrl' => null, 'published' => null, 'updated' => '2026-10-02T08:30:00+00:00'],
            ['title' => 'Upper-case scheme', 'itemUrl' => 'HTTPS://example.org/upper', 'published' => null, 'updated' => null],
        ], $data['items']);
    }

    public function testLimitIsCappedByMaxItems(): void
    {
        $url = self::serverUrl('/feeds/rss.xml');
        $endpoint = $this->endpoint($url, maxItems: 3);

        self::assertCount(2, self::titles($endpoint->respond(['feed' => $url, 'limit' => '2'])));
        self::assertCount(3, self::titles($endpoint->respond(['feed' => $url, 'limit' => '10'])), 'above maxItems');
        self::assertCount(3, self::titles($endpoint->respond(['feed' => $url])), 'no limit');
        self::assertCount(3, self::titles($endpoint->respond(['feed' => $url, 'limit' => '0'])), 'invalid limit');
        self::assertCount(3, self::titles($endpoint->respond(['feed' => $url, 'limit' => 'abc'])), 'invalid limit');
    }

    public function testMissingFeedIsABadRequest(): void
    {
        $endpoint = new FeedEndpoint(null, $this->cacheDir);

        foreach ([[], ['feed' => ''], ['feed' => ['https://example.org/feed']]] as $query) {
            $response = $endpoint->respond($query);
            self::assertSame(400, $response['status']);
            self::assertSame(['error' => 'Missing feed parameter'], self::json($response));
        }
    }

    public function testFeedOutsideTheListIsRefusedWithoutFetching(): void
    {
        $endpoint = $this->endpoint(self::serverUrl('/feeds/rss.xml'));
        $response = $endpoint->respond(['feed' => self::serverUrl('/feeds/atom.xml')]);

        self::assertSame(403, $response['status']);
        self::assertSame(['error' => 'This feed is not allowed'], self::json($response));
        self::assertDirectoryDoesNotExist($this->cacheDir, 'nothing was fetched or cached');
    }

    public function testAnyFeedStillRefusesPrivateAddresses(): void
    {
        $response = (new FeedEndpoint(null, $this->cacheDir))->respond(['feed' => self::serverUrl('/feeds/rss.xml')]);

        self::assertSame(502, $response['status']);
        self::assertSame(['error' => 'Could not load the feed'], self::json($response));
        self::assertStringContainsString('Refusing to fetch from 127.0.0.1', $this->log());
    }

    public function testWhyAFeedFailedIsLoggedNotSent(): void
    {
        $url = self::serverUrl('/status/500');
        $response = $this->endpoint($url)->respond(['feed' => $url]);

        self::assertSame(502, $response['status']);
        self::assertStringNotContainsString('500', $response['body']);
        self::assertStringContainsString("HTTP 500 for {$url}", $this->log());
    }

    public function testFreshCopyIsServedFromTheCache(): void
    {
        [$url, $file] = $this->temporaryFeed();
        $endpoint = $this->endpoint($url);
        self::assertSame(200, $endpoint->respond(['feed' => $url])['status']);

        unlink($file);
        $response = $endpoint->respond(['feed' => $url]);

        self::assertSame(200, $response['status']);
        self::assertSame('RSS feed', self::json($response)['title']);
        self::assertSame('', $this->log(), 'the feed was not fetched again');
    }

    public function testOldCopyIsServedWhenTheFeedFails(): void
    {
        [$url, $file] = $this->temporaryFeed();
        $endpoint = $this->endpoint($url, cacheTtl: 1);
        self::assertSame(200, $endpoint->respond(['feed' => $url])['status']);
        $cachedAt = time();

        unlink($file);
        while (time() <= $cachedAt) {
            usleep(50_000);
        }
        $response = $endpoint->respond(['feed' => $url]);

        self::assertSame(200, $response['status']);
        self::assertSame('RSS feed', self::json($response)['title']);
        self::assertStringContainsString('HTTP 404', $this->log(), 'the feed was fetched again');
    }

    public function testCacheTtlZeroFetchesEveryTime(): void
    {
        [$url, $file] = $this->temporaryFeed();
        $endpoint = $this->endpoint($url, cacheTtl: 0);
        self::assertSame(200, $endpoint->respond(['feed' => $url])['status']);

        unlink($file);

        self::assertSame(502, $endpoint->respond(['feed' => $url])['status']);
        self::assertDirectoryDoesNotExist($this->cacheDir);
    }

    public function testCopyFetchedFromPrivateNetworksIsNotServedToAnEndpointForAnyFeed(): void
    {
        $url = self::serverUrl('/feeds/rss.xml');
        self::assertSame(200, $this->endpoint($url)->respond(['feed' => $url])['status']);

        $response = (new FeedEndpoint(null, $this->cacheDir))->respond(['feed' => $url]);

        self::assertSame(502, $response['status'], 'same cache directory, other settings');
    }

    public function testServesTheAssets(): void
    {
        $endpoint = new FeedEndpoint(null, $this->cacheDir);
        $assets = [
            'js'  => ['rss-block.js', 'text/javascript; charset=utf-8'],
            'css' => ['rss-block.css', 'text/css; charset=utf-8'],
        ];

        foreach ($assets as $name => [$file, $contentType]) {
            $response = $endpoint->respond(['asset' => $name]);

            self::assertSame(200, $response['status']);
            self::assertSame($contentType, $response['headers']['Content-Type']);
            self::assertSame(file_get_contents(dirname(__DIR__) . '/assets/' . $file), $response['body']);
            self::assertArrayHasKey('ETag', $response['headers']);
        }
    }

    public function testAssetIsNotSentAgainWhileTheBrowserHasIt(): void
    {
        $endpoint = new FeedEndpoint(null, $this->cacheDir);
        $etag = $endpoint->respond(['asset' => 'js'])['headers']['ETag'];

        foreach ([$etag, "W/{$etag}", "\"other\", {$etag}"] as $ifNoneMatch) {
            $response = $endpoint->respond(['asset' => 'js'], $ifNoneMatch);
            self::assertSame(304, $response['status'], $ifNoneMatch);
            self::assertSame('', $response['body']);
        }
        self::assertSame(200, $endpoint->respond(['asset' => 'js'], '"other"')['status']);
    }

    public function testUnknownAssetIsNotFound(): void
    {
        $endpoint = new FeedEndpoint(null, $this->cacheDir);

        foreach (['../composer.json', 'rss-block.js', ['js']] as $asset) {
            $response = $endpoint->respond(['asset' => $asset]);
            self::assertSame(404, $response['status']);
            self::assertSame(['error' => 'Unknown asset'], self::json($response));
        }
    }

    public function testHandleSendsTheResponse(): void
    {
        $previousGet = $_GET;
        $_GET = ['asset' => 'css'];
        try {
            ob_start();
            (new FeedEndpoint(null, $this->cacheDir))->handle();
            $output = ob_get_clean();
        } finally {
            $_GET = $previousGet;
        }

        self::assertSame(file_get_contents(dirname(__DIR__) . '/assets/rss-block.css'), $output);
    }

    public function testPrivateNetworksNeedAListOfFeeds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FeedEndpoint(null, allowPrivateNetworks: true);
    }

    public function testMaxItemsMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FeedEndpoint(null, maxItems: 0);
    }

    public function testCacheTtlCannotBeNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FeedEndpoint(null, cacheTtl: -1);
    }
}
