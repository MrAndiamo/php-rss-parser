<?php

declare(strict_types=1);

namespace Timvandendries\PhpRssParser;

use Timvandendries\PhpRssParser\objects\FeedObject;

/**
 * The server side of assets/rss-block.js: call handle() from a public PHP file, and every
 * <div data-rss-feed="https://…"> on a page that loads rss-block.js shows that feed as a block of links.
 *
 *   ?feed=<url>[&limit=<n>]  the feed as JSON: {"title", "siteUrl", "items": [{"title", "itemUrl",
 *                            "published", "updated"}]}. Links are http(s) or null, dates ISO 8601 or null.
 *   ?asset=js, ?asset=css    rss-block.js and rss-block.css, so they don't have to be copied out of vendor/
 *
 * Fetched feeds are cached on disk, so page views don't hit the feed's server every time. When a feed
 * can't be fetched, the last cached copy is served, however old.
 */
final class FeedEndpoint
{
    public const DEFAULT_CACHE_TTL = 600;
    public const DEFAULT_MAX_ITEMS = 50;

    /** Files in the assets directory, by the value of the asset parameter: [file name, Content-Type]. */
    private const ASSETS = [
        'js'  => ['rss-block.js', 'text/javascript; charset=utf-8'],
        'css' => ['rss-block.css', 'text/css; charset=utf-8'],
    ];

    /** Invalid UTF-8 in a feed becomes U+FFFD instead of failing the whole response. */
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    private string $cacheDir;

    /**
     * @param list<string>|null $allowedFeeds the feed URLs blocks may show, compared exactly with their data-rss-feed;
     *                                        null allows any public http(s) feed, so anyone can use the endpoint
     * @param string|null $cacheDir where fetched feeds are cached; null uses php-rss-parser in the system temp directory
     * @param int $cacheTtl seconds a fetched feed is served from the cache before it is fetched again; 0 turns the cache off
     * @param int $maxItems the most items sent for a feed
     * @param bool $allowPrivateNetworks see RssParser::getFeed(); only with a list of $allowedFeeds
     * @throws \InvalidArgumentException for options out of range, or $allowPrivateNetworks without a list of feeds
     */
    public function __construct(
        private readonly ?array $allowedFeeds,
        ?string $cacheDir = null,
        private readonly int $cacheTtl = self::DEFAULT_CACHE_TTL,
        private readonly int $maxItems = self::DEFAULT_MAX_ITEMS,
        private readonly bool $allowPrivateNetworks = false,
    ) {
        if ($allowedFeeds === null && $allowPrivateNetworks) {
            throw new \InvalidArgumentException('$allowPrivateNetworks needs a list of $allowedFeeds, or anyone could make the server fetch from its own network');
        }
        if ($cacheTtl < 0) {
            throw new \InvalidArgumentException('$cacheTtl must be 0 or more');
        }
        if ($maxItems < 1) {
            throw new \InvalidArgumentException('$maxItems must be 1 or more');
        }
        $this->cacheDir = $cacheDir ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-rss-parser';
    }

    /**
     * Answers the current request from $_GET and sends the response.
     *
     * @return void
     */
    public function handle(): void
    {
        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        $response = $this->respond($_GET, is_string($ifNoneMatch) ? $ifNoneMatch : '');

        http_response_code($response['status']);
        foreach ($response['headers'] as $name => $value) {
            header("{$name}: {$value}");
        }
        echo $response['body'];
    }

    /**
     * The response to a request, for frameworks that send responses themselves.
     *
     * Errors are JSON too, {"error": "…"}: 400 without a feed, 403 for a feed that is not allowed, 404 for an
     * unknown asset and 502 when the feed can't be fetched and isn't cached. Why a feed failed goes to
     * error_log(), not to the browser: the reason can name hosts and addresses on the server's network.
     *
     * @param array<mixed> $query the query parameters, like $_GET
     * @param string $ifNoneMatch the request's If-None-Match header, so browsers can keep the assets
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function respond(array $query, string $ifNoneMatch = ''): array
    {
        if (isset($query['asset'])) {
            return self::_asset($query['asset'], $ifNoneMatch);
        }

        $feedUrl = $query['feed'] ?? null;
        if (!is_string($feedUrl) || $feedUrl === '') {
            return self::_json(400, ['error' => 'Missing feed parameter']);
        }
        if ($this->allowedFeeds !== null && !in_array($feedUrl, $this->allowedFeeds, true)) {
            return self::_json(403, ['error' => 'This feed is not allowed']);
        }

        $feed = $this->_feedData($feedUrl);
        if ($feed === null) {
            return self::_json(502, ['error' => 'Could not load the feed']);
        }

        $limit = filter_var($query['limit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $feed['items'] = array_slice($feed['items'], 0, is_int($limit) ? min($limit, $this->maxItems) : $this->maxItems);
        return self::_json(200, $feed);
    }

    /**
     * The feed's data for a block: from the cache while it is fresh, otherwise fetched. When the fetch
     * fails, the cached copy is served however old it is, because an old list beats an error.
     *
     * @param string $feedUrl
     * @return array{items: list<mixed>, ...}|null see _blockData(); null when the feed can't be fetched and isn't cached
     */
    private function _feedData(string $feedUrl): ?array
    {
        $cacheFile = $this->cacheTtl > 0 ? $this->_cacheFile($feedUrl) : null;
        $cached = $cacheFile !== null ? self::_readCache($cacheFile) : null;
        if ($cached !== null && $cached['fetched'] + $this->cacheTtl > time()) {
            return $cached['feed'];
        }

        $feed = RssParser::getFeed($feedUrl, $this->allowPrivateNetworks);
        if ($feed->error !== null) {
            error_log("php-rss-parser FeedEndpoint: {$feed->error}");
            return $cached['feed'] ?? null;
        }

        $data = $this->_blockData($feed);
        if ($cacheFile !== null) {
            self::_writeCache($cacheFile, $data);
        }
        return $data;
    }

    /**
     * What a block shows of a feed, safe to put in a page as text: links only when they are http(s),
     * dates as ISO 8601, and no descriptions or content, which can hold the feed's own HTML.
     *
     * @param FeedObject $feed
     * @return array{title: string, siteUrl: string|null, items: list<array{title: string, itemUrl: string|null, published: string|null, updated: string|null}>}
     */
    private function _blockData(FeedObject $feed): array
    {
        $items = [];
        foreach (array_slice($feed->items ?? [], 0, $this->maxItems) as $item) {
            $items[] = [
                'title'     => $item->title,
                'itemUrl'   => self::_httpUrl($item->itemUrl),
                'published' => self::_isoDate($item->published),
                'updated'   => self::_isoDate($item->updated),
            ];
        }

        return [
            'title'   => $feed->title ?? '',
            'siteUrl' => self::_httpUrl($feed->siteUrl),
            'items'   => $items,
        ];
    }

    /**
     * @param string|null $url
     * @return string|null the URL when it is an absolute http(s) URL, so a javascript: link can't reach the page
     */
    private static function _httpUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return ($scheme === 'http' || $scheme === 'https') && (string) parse_url($url, PHP_URL_HOST) !== '' ? $url : null;
    }

    /**
     * @param string|null $date a date as the feed has it: RFC 822 for RSS, RFC 3339 for Atom
     * @return string|null the date as ISO 8601, or null when there is none or it can't be read
     */
    private static function _isoDate(?string $date): ?string
    {
        // Without the day name: PHP moves "Wed, 01 Oct 2026", a Thursday, to the next Wednesday.
        $date = (string) preg_replace('~^\s*[a-z]+,\s*~i', '', (string) $date);
        if (trim($date) === '') {
            return null;
        }
        $parsed = date_create($date);
        return $parsed !== false ? $parsed->format(DATE_ATOM) : null;
    }

    /**
     * @param string $feedUrl
     * @return string
     */
    private function _cacheFile(string $feedUrl): string
    {
        // The settings that change what is cached are part of the key, so endpoints that share the
        // cache directory never serve each other's copies: a feed fetched with $allowPrivateNetworks
        // can't reach an endpoint that allows any feed.
        $key = hash('sha256', serialize([$feedUrl, $this->allowPrivateNetworks, $this->maxItems]));
        return $this->cacheDir . DIRECTORY_SEPARATOR . $key . '.json';
    }

    /**
     * @param string $file
     * @return array{fetched: int, feed: array{items: list<mixed>, ...}}|null null when there is no readable copy
     */
    private static function _readCache(string $file): ?array
    {
        // The file can disappear between the check and the read; that is a cache miss, not a warning.
        $json = is_file($file) ? @file_get_contents($file) : false;
        $cache = $json !== false ? json_decode($json, true) : null;
        if (!is_array($cache) || !is_int($cache['fetched'] ?? null) || !is_array($cache['feed'] ?? null)) {
            return null;
        }
        $feed = $cache['feed'];
        $items = $feed['items'] ?? null;
        if (!is_array($items)) {
            return null;
        }
        $feed['items'] = array_values($items);
        return ['fetched' => $cache['fetched'], 'feed' => $feed];
    }

    /**
     * Writes the cache file in one step, so a request never reads a half-written copy. A failure only
     * costs the cache and is logged.
     *
     * @param string $file
     * @param array<string, mixed> $feed
     * @return void
     */
    private static function _writeCache(string $file, array $feed): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            error_log("php-rss-parser FeedEndpoint: could not create the cache directory {$dir}");
            return;
        }
        $temp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $json = json_encode(['fetched' => time(), 'feed' => $feed], self::JSON_FLAGS);
        if (@file_put_contents($temp, $json) === false || !@rename($temp, $file)) {
            @unlink($temp);
            error_log("php-rss-parser FeedEndpoint: could not write to the cache directory {$dir}");
        }
    }

    /**
     * @param mixed $name the asset parameter
     * @param string $ifNoneMatch
     * @return array{status: int, headers: array<string, string>, body: string}
     * @throws \LogicException when the package is missing the asset, which means a broken install
     */
    private static function _asset(mixed $name, string $ifNoneMatch): array
    {
        if (!is_string($name) || !isset(self::ASSETS[$name])) {
            return self::_json(404, ['error' => 'Unknown asset']);
        }
        [$fileName, $contentType] = self::ASSETS[$name];
        $path = dirname(__DIR__) . '/assets/' . $fileName;
        $body = is_file($path) ? file_get_contents($path) : false;
        if ($body === false) {
            throw new \LogicException("The package is missing assets/{$fileName}");
        }

        // Browsers may keep the file, but check the ETag every time, so a package update reaches them at once.
        $etag = '"' . md5($body) . '"';
        $headers = [
            'Content-Type'           => $contentType,
            'Cache-Control'          => 'no-cache',
            'ETag'                   => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ];
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            // Compared weakly, as HTTP does for GET: servers that compress responses add W/ to the ETag.
            if (preg_replace('~^W/~', '', trim($candidate)) === $etag) {
                return ['status' => 304, 'headers' => $headers, 'body' => ''];
            }
        }
        return ['status' => 200, 'headers' => $headers, 'body' => $body];
    }

    /**
     * @param int $status
     * @param array<mixed> $data
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function _json(int $status, array $data): array
    {
        return [
            'status'  => $status,
            'headers' => [
                'Content-Type'           => 'application/json; charset=utf-8',
                // The server keeps its own cache; this way a block's refresh always asks it.
                'Cache-Control'          => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'body'    => json_encode($data, self::JSON_FLAGS),
        ];
    }
}
