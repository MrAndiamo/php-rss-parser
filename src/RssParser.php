<?php

declare(strict_types=1);

namespace Timvandendries\PhpRssParser;

use Timvandendries\PhpRssParser\objects\FeedObject;
use Timvandendries\PhpRssParser\objects\ItemObject;

class RssParser
{

    /** @deprecated Atom is detected from the parsed root element; use ATOM_NAMESPACE. */
    public const ATOM_RSS_PATTERN = '<feed xmlns="http://www.w3.org/2005/Atom">';

    public const ATOM_NAMESPACE = 'http://www.w3.org/2005/Atom';
    public const RSS1_NAMESPACE = 'http://purl.org/rss/1.0/';
    public const XHTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';

    public const CONNECT_TIMEOUT = 10;
    public const TIMEOUT = 30;
    public const USER_AGENT = 'Mozilla/5.0 (compatible; php-rss-parser)';

    /** Query parameters whose values are hidden in error messages. */
    private const SECRET_PARAMS = [
        'token', 'access_token', 'auth', 'key', 'apikey', 'api_key',
        'secret', 'password', 'pass', 'signature', 'sig',
    ];

    /** File extensions accepted as images for enclosures that don't declare a type. */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp'];

    /**
     * Fetches a remote feed over http(s) and detects whether it is Atom or RSS.
     * Local files are refused here, so URLs from untrusted input can't read the filesystem.
     *
     * @param string $url
     * @return FeedObject
     */
    public static function getFeed(string $url): FeedObject
    {
        return self::_parse($url, false, fn(\SimpleXMLElement $xml) => self::_isAtom($xml)
            ? self::_buildAtomFeed($xml, $url)
            : self::_buildRSSFeed($xml, $url));
    }

    /**
     * @param string $url an http(s) URL, a file:// URL or a local path
     * @return \Timvandendries\PhpRssParser\objects\FeedObject
     */
    public static function getAtomFeedByUrl(string $url): FeedObject
    {
        return self::_parse($url, true, fn(\SimpleXMLElement $xml) => self::_buildAtomFeed($xml, $url));
    }

    /**
     * @param string $url an http(s) URL, a file:// URL or a local path
     * @return \Timvandendries\PhpRssParser\objects\FeedObject
     */
    public static function getRSSFeedByUrl(string $url): FeedObject
    {
        return self::_parse($url, true, fn(\SimpleXMLElement $xml) => self::_buildRSSFeed($xml, $url));
    }

    /**
     * Loads and parses the feed, turning any failure into a FeedObject with an error.
     *
     * @param string $url
     * @param bool $allowLocal whether file:// URLs and local paths may be read
     * @param callable $builder receives the parsed \SimpleXMLElement and returns a FeedObject
     * @return FeedObject
     */
    private static function _parse(string $url, bool $allowLocal, callable $builder): FeedObject
    {
        try {
            return $builder(self::_loadXml($url, $allowLocal));
        } catch (\Throwable $e) {
            $feed = new FeedObject();
            $feed->error = $e->getMessage();
            return $feed;
        }
    }

    /**
     * @param \SimpleXMLElement $xml
     * @return bool
     */
    private static function _isAtom(\SimpleXMLElement $xml): bool
    {
        return $xml->getName() === 'feed' && self::_atomNs($xml) === self::ATOM_NAMESPACE;
    }

    /**
     * The namespace to read Atom children from: the Atom namespace whatever prefix it uses,
     * or '' for feeds that don't declare it.
     *
     * @param \SimpleXMLElement $xml
     * @return string
     */
    private static function _atomNs(\SimpleXMLElement $xml): string
    {
        return in_array(self::ATOM_NAMESPACE, $xml->getNamespaces(), true) ? self::ATOM_NAMESPACE : '';
    }

    /**
     * Hides credentials and secret query values so error messages don't leak them.
     *
     * @param string $url
     * @return string
     */
    private static function _safeUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return '[invalid url]';
        }

        $query = '';
        if (isset($parts['query'])) {
            $pairs = [];
            foreach (explode('&', $parts['query']) as $pair) {
                $name = explode('=', $pair, 2)[0];
                $pairs[] = in_array(strtolower(urldecode($name)), self::SECRET_PARAMS, true)
                    ? $name . '=REDACTED'
                    : $pair;
            }
            $query = '?' . implode('&', $pairs);
        }

        return ($parts['scheme'] ?? 'http') . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '')
            . $query;
    }

    /**
     * Returns the local filesystem path for a file:// URL or plain path, or NULL for anything else.
     *
     * @param string $url
     * @return string|null
     */
    private static function _localPath(string $url): ?string
    {
        // Windows drive paths like C:\feed.xml parse as scheme "c", so check them first.
        if (preg_match('~^[a-z]:[\\\\/]~i', $url) === 1) {
            return $url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme === '') {
            return $url;
        }
        if ($scheme === 'file') {
            $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
            // file:///C:/feed.xml has path /C:/feed.xml
            return preg_match('~^/[a-z]:~i', $path) === 1 ? substr($path, 1) : $path;
        }
        return NULL;
    }

    /**
     * @param string $url
     * @param bool $allowLocal whether file:// URLs and local paths may be read
     * @return string
     * @throws \RuntimeException when reading fails or doesn't return a 2xx response with a body
     */
    private static function _fetch(string $url, bool $allowLocal): string
    {
        $localPath = self::_localPath($url);
        if ($localPath !== NULL) {
            if (!$allowLocal) {
                throw new \RuntimeException('Local files are not allowed in getFeed(), use getRSSFeedByUrl() or getAtomFeedByUrl()');
            }
            if (!is_file($localPath) || !is_readable($localPath)) {
                throw new \RuntimeException("Could not read file {$localPath}");
            }
            $data = file_get_contents($localPath);
            if ($data === false || $data === '') {
                throw new \RuntimeException("Could not read file {$localPath}");
            }
            return $data;
        }

        $safeUrl = self::_safeUrl($url);

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \RuntimeException("Unsupported URL scheme '{$scheme}', only http and https are allowed");
        }

        $cUrl = curl_init($url);
        if ($cUrl === false) {
            throw new \RuntimeException("cURL init failed for {$safeUrl}");
        }
        curl_setopt_array($cUrl, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 5,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT  => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT         => self::TIMEOUT,
            CURLOPT_USERAGENT       => ini_get('user_agent') ?: self::USER_AGENT,
            CURLOPT_ENCODING        => '',
        ]);
        $data = curl_exec($cUrl);
        $status = curl_getinfo($cUrl, CURLINFO_RESPONSE_CODE);

        if ($data === false) {
            throw new \RuntimeException("cURL error for {$safeUrl}: " . curl_error($cUrl));
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("HTTP {$status} for {$safeUrl}");
        }
        if ($data === '') {
            throw new \RuntimeException("Empty response (HTTP {$status}) for {$safeUrl}");
        }

        return $data;
    }

    /**
     * @param string $url
     * @param bool $allowLocal whether file:// URLs and local paths may be read
     * @return \SimpleXMLElement
     * @throws \RuntimeException when fetching or parsing fails
     */
    private static function _loadXml(string $url, bool $allowLocal): \SimpleXMLElement
    {
        // libxml rejects anything before the XML declaration, so drop a leading BOM and whitespace.
        $data = preg_replace('~^(?:\xEF\xBB\xBF|\s)+~', '', self::_fetch($url, $allowLocal));

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
            throw new \RuntimeException('Could not parse XML from ' . self::_describe($url) . ": {$reason}");
        }

        return $feedData;
    }

    /**
     * @param string $url
     * @return string the URL or path as it may appear in an error message
     */
    private static function _describe(string $url): string
    {
        return self::_localPath($url) ?? self::_safeUrl($url);
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
     * Text of an Atom text construct; for type="xhtml" the markup inside the wrapping div.
     *
     * @param \SimpleXMLElement $element
     * @return string
     */
    private static function _atomText(\SimpleXMLElement $element): string
    {
        if ((string) $element->attributes()['type'] !== 'xhtml') {
            return (string) $element;
        }
        $div = $element->children(self::XHTML_NAMESPACE)->div;
        if (!isset($div[0])) {
            return trim((string) $element);
        }
        $xml = (string) $div->asXML();
        // Strip the wrapping <div ...> and </div>; a self-closing <div/> has no content.
        return preg_match('~^<[^>]*>(.*)</[^>]+>$~s', $xml, $match) === 1 ? $match[1] : '';
    }

    /**
     * @param \SimpleXMLElement $feedData
     * @param string $url the URL the feed was requested from
     * @return FeedObject
     * @throws \RuntimeException when the document is not an Atom feed
     */
    private static function _buildAtomFeed(\SimpleXMLElement $feedData, string $url): FeedObject
    {
        if ($feedData->getName() !== 'feed') {
            throw new \RuntimeException('Not an Atom feed: ' . self::_describe($url) . " has root element <{$feedData->getName()}>");
        }

        $ns = self::_atomNs($feedData);
        $atom = $feedData->children($ns);

        $siteLink = self::_atomLinkByRel($atom->link, 'alternate');
        $selfLink = self::_atomLinkByRel($atom->link, 'self');

        $feed = new FeedObject();
        $feed->id = (string) $atom->id;
        $feed->title = (string) $atom->title;
        $feed->description = NULL;
        $feed->language = NULL;
        $feed->copyright = NULL;
        $feed->webmaster = NULL;
        $feed->updated = (string) $atom->updated;
        $feed->published = NULL;
        $feed->siteUrl = $siteLink !== NULL ? (string) $siteLink['href'] : '';
        $feed->feedUrl = $selfLink !== NULL ? (string) $selfLink['href'] : $url;
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
            $imageLink = self::_atomLink($entry->link, fn(\SimpleXMLElement $attributes) => self::_isImageEnclosure($attributes));

            $item = new ItemObject();
            $item->id = (string) $entry->id;
            $item->title = (string) $entry->title;
            $item->description = isset($entry->summary) ? self::_atomText($entry->summary) : '';
            $item->content = isset($entry->content) ? self::_atomText($entry->content) : '';
            $item->authorName = $author !== NULL ? (string) $author->name : '';
            $item->authorEmail = $author !== NULL ? (string) $author->email : '';
            $item->updated = (string) $entry->updated;
            $item->published = (string) $entry->published;
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
     * @param string $url the URL the feed was requested from
     * @return FeedObject
     * @throws \RuntimeException when the document is not an RSS feed
     */
    private static function _buildRSSFeed(\SimpleXMLElement $feedData, string $url): FeedObject
    {
        if ($feedData->getName() === 'rss') {
            $channel = $feedData->channel;
            $entries = $channel->item;
        } elseif ($feedData->getName() === 'RDF') {
            $rss1 = $feedData->children(self::RSS1_NAMESPACE);
            $channel = $rss1->channel;
            $entries = $rss1->item;
        } else {
            throw new \RuntimeException('Not an RSS feed: ' . self::_describe($url) . " has root element <{$feedData->getName()}>");
        }
        if (!isset($channel[0])) {
            throw new \RuntimeException('Not a valid RSS feed: ' . self::_describe($url) . ' has no <channel>');
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
        $feed->feedUrl = $url;
        $feed->items = self::_getRSSItems($entries);

        return $feed;
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
            $item->itemUrl = $link !== '' ? $link : ($guidIsPermaLink ? (string) $entry->guid : '');
            $item->imageUrl = (string) $entry->enclosure['url'];
            $item->imageType = (string) $entry->enclosure['type'];
            $item->imageTitle = (string) $entry->enclosure['title'];

            $items[] = $item;
        }

        return $items;
    }
}
