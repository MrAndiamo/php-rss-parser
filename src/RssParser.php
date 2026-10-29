<?php

declare(strict_types=1);

namespace Timvandendries\PhpRssParser;

use Timvandendries\PhpRssParser\objects\FeedObject;
use Timvandendries\PhpRssParser\objects\ItemObject;

class RssParser
{

    public const ATOM_NAMESPACE = 'http://www.w3.org/2005/Atom';

    public const CONNECT_TIMEOUT = 10;
    public const TIMEOUT = 30;
    public const USER_AGENT = 'Mozilla/5.0 (compatible; php-rss-parser)';

    /** Query parameters whose values are hidden in error messages. */
    private const SECRET_PARAMS = [
        'token', 'access_token', 'auth', 'key', 'apikey', 'api_key',
        'secret', 'password', 'pass', 'signature', 'sig',
    ];

    /**
     * @param string $url
     * @return FeedObject
     */
    public static function getFeed(string $url): FeedObject
    {
        return self::_parse($url, fn(\SimpleXMLElement $xml) => self::_isAtom($xml)
            ? self::_buildAtomFeed($xml, $url)
            : self::_buildRSSFeed($xml, $url));
    }

    /**
     * @param string $url
     * @return \Timvandendries\PhpRssParser\objects\FeedObject
     */
    public static function getAtomFeedByUrl(string $url): FeedObject
    {
        return self::_parse($url, fn(\SimpleXMLElement $xml) => self::_buildAtomFeed($xml, $url));
    }

    /**
     * @param string $url
     * @return \Timvandendries\PhpRssParser\objects\FeedObject
     */
    public static function getRSSFeedByUrl(string $url): FeedObject
    {
        return self::_parse($url, fn(\SimpleXMLElement $xml) => self::_buildRSSFeed($xml, $url));
    }

    /**
     * Fetches and parses the feed, turning any failure into a FeedObject with an error.
     *
     * @param string $url
     * @param callable $builder receives the parsed \SimpleXMLElement and returns a FeedObject
     * @return FeedObject
     */
    private static function _parse(string $url, callable $builder): FeedObject
    {
        try {
            return $builder(self::_loadXml($url));
        } catch (\RuntimeException $e) {
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
        return $xml->getName() === 'feed' && in_array(self::ATOM_NAMESPACE, $xml->getNamespaces(), true);
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
     * @param string $url
     * @return string
     * @throws \RuntimeException when the request fails or doesn't return a 2xx response with a body
     */
    private static function _fetch(string $url): string
    {
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
     * @return \SimpleXMLElement
     * @throws \RuntimeException when fetching or parsing fails
     */
    private static function _loadXml(string $url): \SimpleXMLElement
    {
        $data = self::_fetch($url);

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
            throw new \RuntimeException('Could not parse XML from ' . self::_safeUrl($url) . ": {$reason}");
        }

        return $feedData;
    }

    /**
     * @param \SimpleXMLElement $links
     * @param string $rel
     * @return \SimpleXMLElement|null the first link with this rel; a missing rel counts as "alternate"
     */
    private static function _atomLink(\SimpleXMLElement $links, string $rel): ?\SimpleXMLElement
    {
        foreach ($links as $link) {
            $linkRel = (string) $link->attributes()['rel'] ?: 'alternate';
            if ($linkRel === $rel) {
                return $link;
            }
        }
        return NULL;
    }

    /**
     * @param \SimpleXMLElement $feedData
     * @param string $url the URL the feed was requested from
     * @return FeedObject
     */
    private static function _buildAtomFeed(\SimpleXMLElement $feedData, string $url): FeedObject
    {
        // Read children in the Atom namespace whatever prefix it uses; '' for feeds without one.
        $ns = in_array(self::ATOM_NAMESPACE, $feedData->getNamespaces(), true) ? self::ATOM_NAMESPACE : '';
        $atom = $feedData->children($ns);

        $siteLink = self::_atomLink($atom->link, 'alternate');
        $selfLink = self::_atomLink($atom->link, 'self');

        $feed = new FeedObject();
        $feed->id = (string) $atom->id;
        $feed->title = (string) $atom->title;
        $feed->description = NULL;
        $feed->language = NULL;
        $feed->copyright = NULL;
        $feed->webmaster = NULL;
        $feed->updated = (string) $atom->updated;
        $feed->published = NULL;
        $feed->siteUrl = $siteLink !== NULL ? (string) $siteLink->attributes()['href'] : '';
        $feed->feedUrl = $selfLink !== NULL ? (string) $selfLink->attributes()['href'] : $url;
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
            $author = $entry->author ? $entry->author->children($ns) : NULL;
            $itemLink = self::_atomLink($entry->link, 'alternate');
            $imageLink = NULL;
            foreach ($entry->link as $link) {
                $attributes = $link->attributes();
                if ((string) $attributes['rel'] === 'enclosure' && strpos((string) $attributes['type'], 'image/') === 0) {
                    $imageLink = $attributes;
                    break;
                }
            }

            $item = new ItemObject();
            $item->id = (string) $entry->id;
            $item->title = (string) $entry->title;
            $item->description = (string) $entry->summary;
            $item->content = (string) $entry->content;
            $item->authorName = $author ? (string) $author->name : '';
            $item->authorEmail = $author ? (string) $author->email : '';
            $item->updated = (string) $entry->updated;
            $item->published = (string) $entry->published;
            $item->itemUrl = $itemLink !== NULL ? (string) $itemLink->attributes()['href'] : NULL;
            $item->imageUrl = $imageLink !== NULL ? (string) $imageLink['href'] : NULL;
            $item->imageType = $imageLink !== NULL ? (string) $imageLink['type'] : NULL;
            $item->imageTitle = $imageLink !== NULL ? (string) $imageLink['title'] : NULL;
            $items[] = $item;
        }
        return $items;
    }

    /**
     * @param \SimpleXMLElement $feedData
     * @param string $url the URL the feed was requested from
     * @return FeedObject
     */
    private static function _buildRSSFeed(\SimpleXMLElement $feedData, string $url): FeedObject
    {
        $feed = new FeedObject();
        $feed->id = NULL;
        $feed->title = (string) $feedData->channel->title;
        $feed->description = (string) $feedData->channel->description;
        $feed->language = (string) $feedData->channel->language;
        $feed->copyright = (string) $feedData->channel->copyright;
        $feed->webmaster = (string) $feedData->channel->webmaster;
        $feed->updated = $feedData->channel->updated ? (string) $feedData->channel->updated : (string) $feedData->channel->lastBuildDate;
        $feed->published = (string) $feedData->channel->pubDate;
        $feed->siteUrl = (string) $feedData->channel->link;
        $feed->feedUrl = $url;
        $feed->items = self::_getRSSItems($feedData->channel->item);

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

            $item = new ItemObject();
            $item->id = (string) $entry->id;
            $item->title = (string) $entry->title;
            $item->description = $entry->description->p ? (string) $entry->description->p : (string) $entry->description;
            $item->content = (string) $entry->content;
            $item->authorName = (string) $entry->author->name;
            $item->authorEmail = (string) $entry->author->email;
            $item->updated = (string) $entry->updated;
            $item->published = (string) $entry->pubDate;
            $item->itemUrl = $link !== '' ? $link : (string) $entry->guid;
            $item->imageUrl = (string) $entry->enclosure['url'];
            $item->imageType = (string) $entry->enclosure['type'];
            $item->imageTitle = (string) $entry->enclosure['title'];

            $items[] = $item;
        }

        return $items;
    }
}
