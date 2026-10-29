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
    public const USER_AGENT = 'Mozilla/5.0 (compatible; php-rss-parser)';

    /** Root namespaces accepted for a <feed>: Atom 1.0, Atom 0.3 and none. */
    private const ATOM_NAMESPACES = [self::ATOM_NAMESPACE, self::ATOM03_NAMESPACE, ''];

    /** Query parameters whose values are hidden in error messages. */
    private const SECRET_PARAMS = [
        'token', 'access_token', 'auth', 'key', 'apikey', 'api_key',
        'secret', 'password', 'pass', 'signature', 'sig',
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
            $safeUrl = self::_validateUrl($url);
            return [self::_fetch($url, $safeUrl), $url, $safeUrl];
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
     * Checks that the URL can be fetched and returns it with credentials and secret query values hidden,
     * for use in error messages.
     *
     * @param string $url
     * @return string
     * @throws \RuntimeException when the URL is not an http(s) URL
     */
    private static function _validateUrl(string $url): string
    {
        if (strpos($url, "\0") !== false) {
            throw new \RuntimeException('URL contains a NUL byte');
        }

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
        if (!isset($parts['host'])) {
            throw new \RuntimeException('Invalid URL: no host');
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

        return $scheme . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '')
            . $query;
    }

    /**
     * @param string $path
     * @return string
     * @throws \RuntimeException when the path is not a readable, non-empty file
     */
    private static function _readFile(string $path): string
    {
        if (strpos($path, "\0") !== false) {
            throw new \RuntimeException('File path contains a NUL byte');
        }
        if (strpos($path, '://') !== false) {
            throw new \RuntimeException('Expected a filesystem path, not a URL; use getFeed() for URLs');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("Could not read file {$path}");
        }
        $data = file_get_contents($path);
        if ($data === false || $data === '') {
            throw new \RuntimeException("Could not read file {$path}");
        }
        return $data;
    }

    /**
     * @param string $url a URL that passed _validateUrl()
     * @param string $safeUrl the URL as returned by _validateUrl(), for error messages
     * @return string
     * @throws \RuntimeException when the request fails or doesn't return a 2xx response with a body
     */
    private static function _fetch(string $url, string $safeUrl): string
    {
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
     * Serializes the children of an element as HTML. HTML has no namespaces, so every element is
     * recreated by its local name: <h:p> becomes <p>, <svg:svg> becomes <svg>, and namespace
     * declarations are dropped. Browsers recognise inline <svg> and <math> by name.
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
        $feed->title = (string) $atom->title;
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
            $item->title = (string) $entry->title;
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
