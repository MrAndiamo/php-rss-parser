<?php

declare(strict_types=1);

namespace Timvandendries\PhpRssParser;

use Timvandendries\PhpRssParser\objects\FeedObject;
use Timvandendries\PhpRssParser\objects\ItemObject;

class RssParser
{

    public const ATOM_NAMESPACE = 'http://www.w3.org/2005/Atom';
    public const ATOM03_NAMESPACE = 'http://purl.org/atom/ns#';
    public const RSS1_NAMESPACE = 'http://purl.org/rss/1.0/';
    public const XHTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';

    public const CONNECT_TIMEOUT = 10;
    public const TIMEOUT = 30;
    public const MAX_REDIRECTS = 5;
    /** Largest feed accepted, in bytes, after decompression. */
    public const MAX_RESPONSE_SIZE = 10 * 1024 * 1024;
    public const USER_AGENT = 'Mozilla/5.0 (compatible; php-rss-parser)';

    /**
     * Whether URLs may point at private, loopback, link-local or otherwise reserved addresses.
     * Off by default, so URLs from user input can't reach internal services (SSRF).
     * Set to true only when every URL passed in is trusted, for example for intranet feeds.
     */
    public static bool $allowPrivateNetworks = false;

    /** Root namespaces accepted for a <feed>: Atom 1.0, Atom 0.3 and none. */
    private const ATOM_NAMESPACES = [self::ATOM_NAMESPACE, self::ATOM03_NAMESPACE, ''];

    /**
     * Query parameter names whose values are hidden in error messages. Names are compared after
     * lowercasing and removing everything but letters and digits, so "Api-Key" matches "apikey".
     */
    private const SECRET_PARAM_NAMES = [
        'key', 'sig', 'pass', 'pwd', 'code', 'jwt', 'sid', 'auth', 'session', 'authorization', 'oauth',
    ];

    /**
     * Parts of query parameter names that mark the value as secret ("client_secret", "X-Amz-Signature").
     * Specific enough not to match ordinary names: "author" and "sessionlist" stay visible.
     */
    private const SECRET_PARAM_PARTS = [
        'token', 'secret', 'passw', 'signature', 'apikey', 'accesskey', 'privatekey',
        'credential', 'cookie', 'sessid', 'sessionid', 'sessionkey', 'authkey', 'authcode',
    ];

    /** Address ranges refused unless $allowPrivateNetworks is set. */
    private const BLOCKED_RANGES = [
        // IPv4
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        // IPv6. IPv4-mapped (::ffff:0:0/96) and well-known NAT64 (64:ff9b::/96) addresses are checked
        // as the IPv4 address they embed. The local-use NAT64 prefix (64:ff9b:1::/48, RFC 8215) embeds
        // IPv4 at an operator-chosen position, so it is blocked as a whole, like IPv4-translated
        // addresses (::ffff:0:0:0/96).
        '::/96', '::ffff:0:0:0/96', '64:ff9b:1::/48', '100::/64', '2001::/32', '2001:db8::/32',
        '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /** File extensions accepted as images for enclosures that don't declare a type. */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp'];

    /**
     * Fetches a feed over http(s) and detects whether it is Atom or RSS.
     *
     * @param string $url
     * @return FeedObject
     */
    public static function getFeed(string $url): FeedObject
    {
        return self::_parseUrl($url, [self::class, '_buildFeed']);
    }

    /**
     * @param string $url an http(s) URL
     * @return \Timvandendries\PhpRssParser\objects\FeedObject
     */
    public static function getAtomFeedByUrl(string $url): FeedObject
    {
        return self::_parseUrl($url, [self::class, '_buildAtomFeed']);
    }

    /**
     * @param string $url an http(s) URL
     * @return \Timvandendries\PhpRssParser\objects\FeedObject
     */
    public static function getRSSFeedByUrl(string $url): FeedObject
    {
        return self::_parseUrl($url, [self::class, '_buildRSSFeed']);
    }

    /**
     * Reads a feed from the local filesystem and detects whether it is Atom or RSS.
     * Only pass trusted paths: this reads any file the PHP process can read.
     *
     * @param string $path a filesystem path, not a URL
     * @return FeedObject
     */
    public static function getFeedFromFile(string $path): FeedObject
    {
        return self::_parseFile($path, [self::class, '_buildFeed']);
    }

    /**
     * Reads an Atom feed from the local filesystem. Only pass trusted paths.
     *
     * @param string $path a filesystem path, not a URL
     * @return FeedObject
     */
    public static function getAtomFeedFromFile(string $path): FeedObject
    {
        return self::_parseFile($path, [self::class, '_buildAtomFeed']);
    }

    /**
     * Reads an RSS feed from the local filesystem. Only pass trusted paths.
     *
     * @param string $path a filesystem path, not a URL
     * @return FeedObject
     */
    public static function getRSSFeedFromFile(string $path): FeedObject
    {
        return self::_parseFile($path, [self::class, '_buildRSSFeed']);
    }

    /**
     * @param string $url
     * @param callable $builder see _parse()
     * @return FeedObject
     */
    private static function _parseUrl(string $url, callable $builder): FeedObject
    {
        return self::_parse(function () use ($url): array {
            $target = self::_prepareUrl($url);
            return [self::_fetch($target), $url, $target['safeUrl']];
        }, $builder);
    }

    /**
     * @param string $path
     * @param callable $builder see _parse()
     * @return FeedObject
     */
    private static function _parseFile(string $path, callable $builder): FeedObject
    {
        return self::_parse(fn(): array => [self::_readFile($path), $path, $path], $builder);
    }

    /**
     * Reads and parses the feed, turning failures caused by the feed or its location into a
     * FeedObject with an error. Every such failure is a \RuntimeException; anything else is a
     * bug in this library and is not caught.
     *
     * @param callable $load returns [string $body, string $feedUrl, string $source], where $source is
     *                       the URL or path as it may appear in error messages
     * @param callable $builder receives the parsed \SimpleXMLElement, $feedUrl and $source, returns a FeedObject
     * @return FeedObject
     */
    private static function _parse(callable $load, callable $builder): FeedObject
    {
        try {
            [$data, $feedUrl, $source] = $load();
            return $builder(self::_parseXml($data, $source), $feedUrl, $source);
        } catch (\RuntimeException $e) {
            $feed = new FeedObject();
            $feed->error = $e->getMessage();
            return $feed;
        }
    }

    /**
     * @param \SimpleXMLElement $xml
     * @param string $feedUrl
     * @param string $source
     * @return FeedObject
     */
    private static function _buildFeed(\SimpleXMLElement $xml, string $feedUrl, string $source): FeedObject
    {
        return $xml->getName() === 'feed'
            ? self::_buildAtomFeed($xml, $feedUrl, $source)
            : self::_buildRSSFeed($xml, $feedUrl, $source);
    }

    /**
     * The namespace of the element itself, whatever prefix it uses; '' when it has none.
     *
     * @param \SimpleXMLElement $xml
     * @return string
     */
    private static function _namespaceOf(\SimpleXMLElement $xml): string
    {
        return dom_import_simplexml($xml)->namespaceURI ?? '';
    }

    /**
     * Checks that the URL can be fetched and rebuilds it from its parsed parts. cURL is given the
     * rebuilt URL, so cURL connects to exactly the host that was checked here.
     *
     * @param string $url
     * @return array{url: string, safeUrl: string, host: string, port: int, isIp: bool}
     *         url: the URL to request; safeUrl: the same without credentials and with secret query
     *         values hidden, for error messages; host: the lowercase ASCII host without brackets;
     *         isIp: whether the host is an IP address
     * @throws \RuntimeException when the URL is not a usable http(s) URL
     */
    private static function _prepareUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false) {
            throw new \RuntimeException('Invalid URL');
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        // No scheme, file://, or a Windows drive letter (C:\feed.xml parses as scheme "c") means a local file.
        if ($scheme === '' || $scheme === 'file' || strlen($scheme) === 1) {
            throw new \RuntimeException('URL must start with http:// or https://; use getFeedFromFile() for local files');
        }
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \RuntimeException("Unsupported URL scheme '{$scheme}', only http and https are allowed");
        }
        if (!isset($parts['host']) || $parts['host'] === '') {
            throw new \RuntimeException('Invalid URL: no host');
        }
        // Spaces and control characters are never valid in a URL, and URL parsers treat them differently.
        // Checked after the scheme, so local paths with spaces get the getFeedFromFile() hint above.
        if (preg_match('~[\x00-\x20\x7F]~', $url) === 1) {
            throw new \RuntimeException('Invalid URL: contains spaces or control characters');
        }

        // URL parsers disagree on the host when the authority has a backslash or more than one "@"
        // (http://a@127.0.0.1\@example.com/), so refuse those instead of guessing.
        if (preg_match('~^[^:]+://([^/?#]*)~', $url, $match) === 1
            && (strpos($match[1], '\\') !== false || substr_count($match[1], '@') > 1)) {
            throw new \RuntimeException('Invalid URL: ambiguous host');
        }

        $host = $parts['host'];
        $isIp = true;
        if ($host[0] === '[') {
            $host = strtolower(substr($host, 1, -1));
            if (substr($parts['host'], -1) !== ']' || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new \RuntimeException('Invalid URL: bad IPv6 address');
            }
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $host = self::_asciiHost($host);
            $isIp = false;
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $authority = (strpos($host, ':') !== false ? "[{$host}]" : $host)
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $userInfo = isset($parts['user'])
            ? $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@'
            : '';
        $path = $parts['path'] ?? '';

        $query = '';
        $safeQuery = '';
        if (isset($parts['query'])) {
            $query = '?' . $parts['query'];
            $pairs = [];
            foreach (explode('&', $parts['query']) as $pair) {
                $name = explode('=', $pair, 2)[0];
                $pairs[] = self::_isSecretParam($name) ? $name . '=REDACTED' : $pair;
            }
            $safeQuery = '?' . implode('&', $pairs);
        }

        return [
            'url'     => "{$scheme}://{$userInfo}{$authority}{$path}{$query}",
            'safeUrl' => "{$scheme}://{$authority}{$path}{$safeQuery}",
            'host'    => $host,
            'port'    => $port,
            'isIp'    => $isIp,
        ];
    }

    /**
     * Normalizes a host name the way DNS sees it: lowercase, without a trailing dot, and
     * internationalized names converted to their ASCII (punycode) form.
     *
     * @param string $host
     * @return string
     * @throws \RuntimeException when the host name is invalid, or is internationalized without ext-intl
     */
    private static function _asciiHost(string $host): string
    {
        $host = rtrim(strtolower($host), '.');
        if (preg_match('~[^\x00-\x7F]~', $host) === 1) {
            if (!function_exists('idn_to_ascii')) {
                throw new \RuntimeException("Internationalized host name {$host} needs the intl extension");
            }
            $ascii = idn_to_ascii($host, IDNA_DEFAULT | IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw new \RuntimeException("Invalid host name {$host}");
            }
            $host = $ascii;
        }
        // Letters, digits, hyphens and underscores in dot-separated labels of at most 63 characters.
        if (strlen($host) > 253 || preg_match('~^[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?(?:\.[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?)*$~', $host) !== 1) {
            throw new \RuntimeException("Invalid host name {$host}");
        }
        return $host;
    }

    /**
     * @param string $name a query parameter name, possibly URL-encoded
     * @return bool whether its value should be hidden in error messages
     */
    private static function _isSecretParam(string $name): bool
    {
        $normalized = (string) preg_replace('~[^a-z0-9]~', '', strtolower(urldecode($name)));
        if (in_array($normalized, self::SECRET_PARAM_NAMES, true)) {
            return true;
        }
        foreach (self::SECRET_PARAM_PARTS as $part) {
            if (strpos($normalized, $part) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolves the host and checks that none of its addresses is private or reserved.
     *
     * Uses the system resolver (hosts file and cache included), which gives IPv4 addresses; only hosts
     * without any IPv4 address get a second, IPv6 lookup. These lookups use the system resolver's own
     * timeouts, which this class can't shorten.
     *
     * @param string $host as returned by _prepareUrl()
     * @return string[] all addresses, to connect to so DNS can't change between check and request
     * @throws \RuntimeException when the host doesn't resolve or resolves to a blocked address
     */
    private static function _resolvePublicAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } else {
            $addresses = gethostbynamel($host) ?: [];
            if ($addresses === []) {
                foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                    if (isset($record['ipv6'])) {
                        $addresses[] = $record['ipv6'];
                    }
                }
            }
        }

        if ($addresses === []) {
            throw new \RuntimeException("Could not resolve host {$host}");
        }
        foreach ($addresses as $address) {
            if (self::_isBlockedAddress($address)) {
                throw new \RuntimeException("Refusing to fetch from {$host}: it resolves to the private or reserved address {$address}");
            }
        }
        return $addresses;
    }

    /**
     * @param string $address an IPv4 or IPv6 address
     * @return bool whether the address is in one of BLOCKED_RANGES; unparsable addresses count as blocked
     */
    private static function _isBlockedAddress(string $address): bool
    {
        $binary = @inet_pton($address);
        if ($binary === false) {
            return true;
        }

        // IPv4-mapped (::ffff:a.b.c.d) and NAT64 (64:ff9b::a.b.c.d) addresses reach the embedded IPv4 address.
        if (strlen($binary) === 16) {
            $prefix = substr($binary, 0, 12);
            if ($prefix === str_repeat("\0", 10) . "\xFF\xFF" || $prefix === "\x00\x64\xFF\x9B" . str_repeat("\0", 8)) {
                return self::_isBlockedAddress((string) inet_ntop(substr($binary, 12)));
            }
        }

        foreach (self::BLOCKED_RANGES as $range) {
            [$network, $bits] = explode('/', $range);
            $networkBinary = inet_pton($network);
            if (strlen($networkBinary) !== strlen($binary)) {
                continue;
            }
            $bits = (int) $bits;
            $fullBytes = intdiv($bits, 8);
            if (strncmp($binary, $networkBinary, $fullBytes) !== 0) {
                continue;
            }
            $remainingBits = $bits % 8;
            if ($remainingBits === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
            if ((ord($binary[$fullBytes]) & $mask) === (ord($networkBinary[$fullBytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $path
     * @return string
     * @throws \RuntimeException when the path is not a readable, non-empty file of at most MAX_RESPONSE_SIZE bytes
     */
    private static function _readFile(string $path): string
    {
        if (strpos($path, "\0") !== false) {
            throw new \RuntimeException('File path contains a NUL byte');
        }
        // Only a scheme at the start makes it a URL; "/cache/http://host/feed.xml" is a valid path.
        // Two or more letters, so a Windows drive like C:/ isn't mistaken for a scheme.
        if (preg_match('~^[a-z][a-z0-9+.-]+://~i', $path) === 1) {
            throw new \RuntimeException('Expected a filesystem path, not a URL; use getFeed() for URLs');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("Could not read file {$path}");
        }
        // Read at most one byte more than allowed, so the limit holds even if the file grows or reports a wrong size.
        $data = file_get_contents($path, false, NULL, 0, self::MAX_RESPONSE_SIZE + 1);
        if ($data === false || $data === '') {
            throw new \RuntimeException("Could not read file {$path}");
        }
        if (strlen($data) > self::MAX_RESPONSE_SIZE) {
            throw new \RuntimeException("File {$path} is larger than " . self::MAX_RESPONSE_SIZE . ' bytes');
        }
        return $data;
    }

    /**
     * Fetches the URL, following redirects one at a time so every hop gets the same checks.
     * TIMEOUT covers all requests together, not each one.
     *
     * @param array{url: string, safeUrl: string, host: string, port: int, isIp: bool} $target from _prepareUrl()
     * @return string
     * @throws \RuntimeException when the request fails or doesn't return a 2xx response with a body
     */
    private static function _fetch(array $target): string
    {
        $deadline = microtime(true) + self::TIMEOUT;
        for ($redirects = 0; ; $redirects++) {
            [$status, $data, $location] = self::_request($target, $deadline);

            if ($status >= 300 && $status < 400 && $location !== '') {
                if ($redirects >= self::MAX_REDIRECTS) {
                    throw new \RuntimeException("Too many redirects for {$target['safeUrl']}");
                }
                $target = self::_prepareUrl($location);
                continue;
            }
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException("HTTP {$status} for {$target['safeUrl']}");
            }
            if ($data === '') {
                throw new \RuntimeException("Empty response (HTTP {$status}) for {$target['safeUrl']}");
            }
            return $data;
        }
    }

    /**
     * Makes a single request without following redirects.
     *
     * @param array{url: string, safeUrl: string, host: string, port: int, isIp: bool} $target from _prepareUrl()
     * @param float $deadline microtime(true) by which the whole fetch must be done
     * @return array{0: int, 1: string, 2: string} status code, body, and the absolute redirect URL or ''
     * @throws \RuntimeException when the host is blocked, the time is up, the request fails or the body is too large
     */
    private static function _request(array $target, float $deadline): array
    {
        $safeUrl = $target['safeUrl'];
        $options = [
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT       => ini_get('user_agent') ?: self::USER_AGENT,
            CURLOPT_ENCODING        => '',
            // Needed for timeouts below one second on systems where cURL uses signals for DNS timeouts.
            CURLOPT_NOSIGNAL        => true,
            // Stops early when the server announces a too large body; the write function
            // below enforces the limit on the decompressed body.
            CURLOPT_MAXFILESIZE     => self::MAX_RESPONSE_SIZE,
        ];

        if (!self::$allowPrivateNetworks) {
            // A proxy would resolve and connect to the host itself, out of reach of the address check,
            // so connect directly. Proxies (also from http_proxy and similar) need $allowPrivateNetworks.
            $options[CURLOPT_PROXY] = '';
            $addresses = self::_resolvePublicAddresses($target['host']);
            // Connect only to the checked addresses, so DNS can't point the host elsewhere after the check.
            // All of them, so cURL can still fall back to the next one when one is down.
            if (!$target['isIp']) {
                $pinned = array_map(fn(string $address) => strpos($address, ':') !== false ? "[{$address}]" : $address, $addresses);
                $options[CURLOPT_RESOLVE] = ["{$target['host']}:{$target['port']}:" . implode(',', $pinned)];
            }
        }

        $remainingMs = (int) (($deadline - microtime(true)) * 1000);
        if ($remainingMs <= 0) {
            throw new \RuntimeException("Timed out after " . self::TIMEOUT . " seconds fetching {$safeUrl}");
        }
        $options[CURLOPT_TIMEOUT_MS] = $remainingMs;
        $options[CURLOPT_CONNECTTIMEOUT_MS] = min(self::CONNECT_TIMEOUT * 1000, $remainingMs);

        $body = '';
        $tooLarge = false;
        $options[CURLOPT_WRITEFUNCTION] = function ($cUrl, string $chunk) use (&$body, &$tooLarge): int {
            if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE_SIZE) {
                $tooLarge = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        };

        $cUrl = curl_init($target['url']);
        if ($cUrl === false) {
            throw new \RuntimeException("cURL init failed for {$safeUrl}");
        }
        curl_setopt_array($cUrl, $options);
        $result = curl_exec($cUrl);

        if ($tooLarge || curl_errno($cUrl) === CURLE_FILESIZE_EXCEEDED) {
            throw new \RuntimeException("Response for {$safeUrl} is larger than " . self::MAX_RESPONSE_SIZE . ' bytes');
        }
        if ($result === false) {
            throw new \RuntimeException("cURL error for {$safeUrl}: " . curl_error($cUrl));
        }

        return [
            (int) curl_getinfo($cUrl, CURLINFO_RESPONSE_CODE),
            $body,
            (string) curl_getinfo($cUrl, CURLINFO_REDIRECT_URL),
        ];
    }

    /**
     * @param string $data the raw feed body
     * @param string $source the URL or path as it may appear in error messages
     * @return \SimpleXMLElement
     * @throws \RuntimeException when the body is not well-formed XML
     */
    private static function _parseXml(string $data, string $source): \SimpleXMLElement
    {
        // libxml rejects anything before the XML declaration, so skip a leading BOM and whitespace.
        $offset = strncmp($data, "\xEF\xBB\xBF", 3) === 0 ? 3 : 0;
        $offset += strspn($data, " \t\r\n", $offset);
        if ($offset > 0) {
            $data = substr($data, $offset);
        }

        $useInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $feedData = simplexml_load_string($data);
        $xmlErrors = libxml_get_errors();

        libxml_clear_errors();
        libxml_use_internal_errors($useInternalErrors);

        if ($feedData === false) {
            $xmlError = NULL;
            foreach ($xmlErrors as $candidate) {
                if ($candidate->level === LIBXML_ERR_FATAL) {
                    $xmlError = $candidate;
                    break;
                }
            }
            $xmlError = $xmlError ?? (end($xmlErrors) ?: NULL);
            $reason = $xmlError
                ? trim($xmlError->message) . " (line {$xmlError->line})"
                : 'unknown error';
            throw new \RuntimeException("Could not parse XML from {$source}: {$reason}");
        }

        return $feedData;
    }

    /**
     * @param \SimpleXMLElement $links
     * @param callable $matches receives a link's attributes and returns whether it is the one wanted
     * @return \SimpleXMLElement|null the attributes of the first matching link
     */
    private static function _atomLink(\SimpleXMLElement $links, callable $matches): ?\SimpleXMLElement
    {
        foreach ($links as $link) {
            $attributes = $link->attributes();
            if ($matches($attributes)) {
                return $attributes;
            }
        }
        return NULL;
    }

    /**
     * @param \SimpleXMLElement $links
     * @param string $rel
     * @return \SimpleXMLElement|null the attributes of the first link with this rel; a missing rel counts as "alternate"
     */
    private static function _atomLinkByRel(\SimpleXMLElement $links, string $rel): ?\SimpleXMLElement
    {
        return self::_atomLink($links, fn(\SimpleXMLElement $attributes) => ((string) $attributes['rel'] ?: 'alternate') === $rel);
    }

    /**
     * @param \SimpleXMLElement $attributes
     * @return bool whether the link is an image enclosure, by its type or, without one, by its file extension
     */
    private static function _isImageEnclosure(\SimpleXMLElement $attributes): bool
    {
        if ((string) $attributes['rel'] !== 'enclosure') {
            return false;
        }
        $type = (string) $attributes['type'];
        if ($type !== '') {
            return strpos($type, 'image/') === 0;
        }
        $extension = strtolower(pathinfo((string) parse_url((string) $attributes['href'], PHP_URL_PATH), PATHINFO_EXTENSION));
        return in_array($extension, self::IMAGE_EXTENSIONS, true);
    }

    /**
     * Text of an Atom text construct. Inline markup is returned as HTML: Atom 1.0 type="xhtml",
     * and Atom 0.3 mode="xml", which is the default mode in Atom 0.3. Atom 0.3 mode="base64" is decoded.
     * The result is untrusted feed content: it is not sanitized, like every other text and HTML field.
     *
     * @param \SimpleXMLElement $element
     * @param string $ns the namespace of the feed
     * @return string
     * @throws \RuntimeException when the inline markup can't be converted to HTML
     */
    private static function _atomText(\SimpleXMLElement $element, string $ns): string
    {
        $attributes = $element->attributes();
        $dom = dom_import_simplexml($element);

        if ((string) $attributes['type'] === 'xhtml') {
            foreach ($dom->childNodes as $child) {
                if ($child instanceof \DOMElement && $child->namespaceURI === self::XHTML_NAMESPACE && $child->localName === 'div') {
                    return self::_innerHtml($child);
                }
            }
            return trim((string) $element);
        }

        if ($ns === self::ATOM03_NAMESPACE) {
            $mode = (string) $attributes['mode'] ?: 'xml';
            if ($mode === 'base64') {
                $decoded = base64_decode((string) $element, true);
                return $decoded === false ? '' : $decoded;
            }
            // Plain text in mode="xml" has no child elements and is returned as text, not escaped HTML.
            if ($mode === 'xml' && $element->count() > 0) {
                return self::_innerHtml($dom);
            }
        }

        return (string) $element;
    }

    /**
     * Text of an Atom text construct as plain text, for fields that never hold markup, like titles.
     * Markup (Atom 1.0 type="html" or "xhtml", Atom 0.3 HTML types and inline XML) is reduced to its text,
     * and whitespace runs become single spaces. The result is untrusted text: escape it for HTML output.
     *
     * @param \SimpleXMLElement $element
     * @param string $ns the namespace of the feed
     * @return string
     */
    private static function _atomPlainText(\SimpleXMLElement $element, string $ns): string
    {
        $attributes = $element->attributes();
        $type = (string) $attributes['type'];
        $dom = dom_import_simplexml($element);

        if ($type === 'xhtml') {
            foreach ($dom->childNodes as $child) {
                if ($child instanceof \DOMElement && $child->namespaceURI === self::XHTML_NAMESPACE && $child->localName === 'div') {
                    return self::_collapseWhitespace($child->textContent);
                }
            }
            return self::_collapseWhitespace((string) $element);
        }

        if ($ns === self::ATOM03_NAMESPACE) {
            $mode = (string) $attributes['mode'] ?: 'xml';
            if ($mode === 'xml' && $element->count() > 0) {
                return self::_collapseWhitespace($dom->textContent);
            }
            $text = $mode === 'base64' ? (string) base64_decode((string) $element, true) : (string) $element;
            $isHtml = $type === 'text/html' || $type === 'application/xhtml+xml';
        } else {
            $text = (string) $element;
            $isHtml = $type === 'html';
        }

        return self::_collapseWhitespace($isHtml ? self::_htmlToText($text) : $text);
    }

    /**
     * @param string $html an HTML fragment
     * @return string its text, without the contents of <script> and <style>
     */
    private static function _htmlToText(string $html): string
    {
        $doc = new \DOMDocument();
        $useInternalErrors = libxml_use_internal_errors(true);
        // The XML declaration makes libxml read the fragment as UTF-8 instead of ISO-8859-1.
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8"?><body>' . $html . '</body>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($useInternalErrors);

        if (!$loaded || $doc->documentElement === NULL) {
            return strip_tags($html);
        }
        foreach (['script', 'style'] as $tagName) {
            $elements = $doc->getElementsByTagName($tagName);
            // Live list: remove from the end so the indexes of the rest don't shift.
            for ($i = $elements->length - 1; $i >= 0; $i--) {
                $element = $elements->item($i);
                $element->parentNode->removeChild($element);
            }
        }
        return $doc->documentElement->textContent;
    }

    /**
     * @param string $text
     * @return string the text trimmed, with every run of whitespace replaced by one space
     */
    private static function _collapseWhitespace(string $text): string
    {
        return trim((string) preg_replace('~[ \t\r\n]+~', ' ', $text));
    }

    /**
     * Serializes the children of an element as HTML. HTML has no namespaces, so every element is
     * recreated by its local name: <h:p> becomes <p>, <svg:svg> becomes <svg>, and namespace
     * declarations are dropped. Browsers recognise inline <svg> and <math> by name.
     * This changes the markup's form only: scripts, event handlers and javascript: URLs are kept.
     *
     * @param \DOMElement $element
     * @return string
     * @throws \RuntimeException when the markup can't be converted
     */
    private static function _innerHtml(\DOMElement $element): string
    {
        try {
            $doc = new \DOMDocument('1.0', 'UTF-8');
            $wrapper = $doc->appendChild($doc->createElement('div'));
            foreach ($element->childNodes as $child) {
                $copy = self::_importAsHtml($doc, $child);
                if ($copy !== NULL) {
                    $wrapper->appendChild($copy);
                }
            }

            $html = '';
            foreach ($wrapper->childNodes as $child) {
                $html .= $doc->saveHTML($child);
            }
            return $html;
        } catch (\DOMException $e) {
            throw new \RuntimeException('Could not convert inline markup to HTML: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param \DOMDocument $doc
     * @param \DOMNode $node
     * @return \DOMNode|null a namespace-free copy of $node in $doc, or NULL for nodes HTML can't hold
     * @throws \DOMException
     */
    private static function _importAsHtml(\DOMDocument $doc, \DOMNode $node): ?\DOMNode
    {
        if ($node instanceof \DOMElement) {
            $element = $doc->createElement($node->localName);
            foreach ($node->attributes as $attribute) {
                $element->setAttribute($attribute->nodeName, $attribute->value);
            }
            foreach ($node->childNodes as $child) {
                $copy = self::_importAsHtml($doc, $child);
                if ($copy !== NULL) {
                    $element->appendChild($copy);
                }
            }
            return $element;
        }
        // Check CDATA before text: DOMCdataSection extends DOMText.
        if ($node instanceof \DOMCdataSection || $node instanceof \DOMText) {
            return $doc->createTextNode($node->data);
        }
        if ($node instanceof \DOMComment) {
            return $doc->createComment($node->data);
        }
        return NULL;
    }

    /**
     * Supports Atom 1.0, Atom 0.3 and <feed> documents without a namespace.
     *
     * @param \SimpleXMLElement $feedData
     * @param string $feedUrl used as feedUrl when the feed has no rel="self" link
     * @param string $source the URL or path as it may appear in error messages
     * @return FeedObject
     * @throws \RuntimeException when the document is not an Atom feed
     */
    private static function _buildAtomFeed(\SimpleXMLElement $feedData, string $feedUrl, string $source): FeedObject
    {
        $ns = self::_namespaceOf($feedData);
        if ($feedData->getName() !== 'feed') {
            throw new \RuntimeException("Not an Atom feed: {$source} has root element <{$feedData->getName()}>");
        }
        if (!in_array($ns, self::ATOM_NAMESPACES, true)) {
            throw new \RuntimeException("Not an Atom feed: {$source} has a <feed> root in namespace {$ns}");
        }

        $atom = $feedData->children($ns);

        $siteLink = self::_atomLinkByRel($atom->link, 'alternate');
        $selfLink = self::_atomLinkByRel($atom->link, 'self');

        $feed = new FeedObject();
        $feed->id = (string) $atom->id;
        $feed->title = isset($atom->title) ? self::_atomPlainText($atom->title, $ns) : '';
        $feed->description = NULL;
        $feed->language = NULL;
        $feed->copyright = NULL;
        $feed->webmaster = NULL;
        // Atom 0.3 calls this <modified>.
        $feed->updated = (string) $atom->updated ?: (string) $atom->modified;
        $feed->published = NULL;
        $feed->siteUrl = $siteLink !== NULL ? (string) $siteLink['href'] : '';
        $feed->feedUrl = $selfLink !== NULL ? (string) $selfLink['href'] : $feedUrl;
        $feed->items = self::_getAtomItems($atom->entry, $ns);

        return $feed;
    }

    /**
     * @param \SimpleXMLElement $entries
     * @param string $ns
     * @return ItemObject[]
     */
    private static function _getAtomItems(\SimpleXMLElement $entries, string $ns): array
    {
        $items = [];
        foreach ($entries as $entryElement) {
            $entry = $entryElement->children($ns);
            $author = isset($entry->author) ? $entry->author->children($ns) : NULL;
            $itemLink = self::_atomLinkByRel($entry->link, 'alternate');
            $imageLink = self::_atomLink($entry->link, [self::class, '_isImageEnclosure']);

            $item = new ItemObject();
            $item->id = (string) $entry->id;
            $item->title = isset($entry->title) ? self::_atomPlainText($entry->title, $ns) : '';
            $item->description = isset($entry->summary) ? self::_atomText($entry->summary, $ns) : '';
            $item->content = isset($entry->content) ? self::_atomText($entry->content, $ns) : '';
            $item->authorName = $author !== NULL ? (string) $author->name : '';
            $item->authorEmail = $author !== NULL ? (string) $author->email : '';
            // Atom 0.3 calls these <modified> and <issued>.
            $item->updated = (string) $entry->updated ?: (string) $entry->modified;
            $item->published = (string) $entry->published ?: (string) $entry->issued;
            $item->itemUrl = $itemLink !== NULL ? (string) $itemLink['href'] : NULL;
            $item->imageUrl = $imageLink !== NULL ? (string) $imageLink['href'] : NULL;
            $item->imageType = $imageLink !== NULL ? (string) $imageLink['type'] : NULL;
            $item->imageTitle = $imageLink !== NULL ? (string) $imageLink['title'] : NULL;
            $items[] = $item;
        }
        return $items;
    }

    /**
     * Supports RSS 0.9x/2.0 (<rss><channel><item>) and RSS 1.0 (<rdf:RDF> with <channel> and <item> siblings).
     *
     * @param \SimpleXMLElement $feedData
     * @param string $feedUrl
     * @param string $source the URL or path as it may appear in error messages
     * @return FeedObject
     * @throws \RuntimeException when the document is not an RSS feed
     */
    private static function _buildRSSFeed(\SimpleXMLElement $feedData, string $feedUrl, string $source): FeedObject
    {
        if ($feedData->getName() === 'rss') {
            $channel = $feedData->channel;
            $entries = $channel->item;
        } elseif ($feedData->getName() === 'RDF') {
            $rss1 = $feedData->children(self::RSS1_NAMESPACE);
            $channel = $rss1->channel;
            $entries = $rss1->item;
        } else {
            throw new \RuntimeException("Not an RSS feed: {$source} has root element <{$feedData->getName()}>");
        }
        if (!isset($channel[0])) {
            throw new \RuntimeException("Not a valid RSS feed: {$source} has no <channel>");
        }

        $feed = new FeedObject();
        $feed->id = NULL;
        $feed->title = (string) $channel->title;
        $feed->description = (string) $channel->description;
        $feed->language = (string) $channel->language;
        $feed->copyright = (string) $channel->copyright;
        $feed->webmaster = (string) $channel->webmaster;
        $feed->updated = $channel->updated ? (string) $channel->updated : (string) $channel->lastBuildDate;
        $feed->published = (string) $channel->pubDate;
        $feed->siteUrl = (string) $channel->link;
        $feed->feedUrl = $feedUrl;
        $feed->items = self::_getRSSItems($entries);

        return $feed;
    }

    /**
     * @param string $value
     * @return bool whether the value is an absolute http(s) URL
     */
    private static function _isHttpUrl(string $value): bool
    {
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        return ($scheme === 'http' || $scheme === 'https') && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * @param object $entries
     * @return ItemObject[]
     */
    private static function _getRSSItems(object $entries): array
    {
        $items = [];
        foreach ($entries as $entry) {
            $link = trim((string) $entry->link);
            $guid = trim((string) $entry->guid);
            $guidIsPermaLink = strtolower((string) $entry->guid['isPermaLink']) !== 'false';

            $item = new ItemObject();
            $item->id = (string) $entry->id;
            $item->title = (string) $entry->title;
            $item->description = $entry->description->p ? (string) $entry->description->p : (string) $entry->description;
            $item->content = (string) $entry->content;
            $item->authorName = (string) $entry->author->name;
            $item->authorEmail = (string) $entry->author->email;
            $item->updated = (string) $entry->updated;
            $item->published = (string) $entry->pubDate;
            if ($link !== '') {
                $item->itemUrl = $link;
            } elseif ($guidIsPermaLink && self::_isHttpUrl($guid)) {
                // A guid is a permalink by default, but feeds often put plain IDs in it anyway.
                $item->itemUrl = $guid;
            } else {
                $item->itemUrl = NULL;
            }
            $item->imageUrl = (string) $entry->enclosure['url'];
            $item->imageType = (string) $entry->enclosure['type'];
            $item->imageTitle = (string) $entry->enclosure['title'];

            $items[] = $item;
        }

        return $items;
    }
}
