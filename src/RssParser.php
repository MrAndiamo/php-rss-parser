<?php

declare(strict_types=1);

namespace Timvandendries\PhpRssParser;

use Timvandendries\PhpRssParser\objects\FeedObject;
use Timvandendries\PhpRssParser\objects\ItemObject;

class RssParser
{

    public const ATOM_RSS_PATTERN = '<feed xmlns="http://www.w3.org/2005/Atom">';

    /**
     * @param string $url
     * @return FeedObject
     */
    public static function getFeed(string $url): FeedObject
    {
        $cUrl = curl_init($url);
        curl_setopt($cUrl, CURLOPT_RETURNTRANSFER, true);
        $data = curl_exec($cUrl);
        if (!is_bool($data)) {
            $data =  preg_replace('/^.+\n/', '', $data);
            if (substr(str_replace(array("\r", "\n"), '', $data), 0, 42) == self::ATOM_RSS_PATTERN) {
                $feed = self::getAtomFeedByUrl($url);
            } else {
                $feed = self::getRSSFeedByUrl($url);
            }
        } else {
            $feed = new FeedObject;
        }
        return $feed;
    }

    /**
     * @param string $url
     * @return \Timvandendries\PhpRssParser\objects\FeedObject
     */
    public static function getAtomFeedByUrl(string $url): FeedObject
    {

        $feedData = simplexml_load_file($url);

        $feed = new FeedObject();
        $feed->id = (string) $feedData->id;
        $feed->title = (string) $feedData->title;
        $feed->description = NULL;
        $feed->language = NULL;
        $feed->copyright = NULL;
        $feed->webmaster = NULL;
        $feed->updated = (string) $feedData->updated;
        $feed->published = NULL;
        $feed->siteUrl = (string) $feedData->link[0]['href'];
        $feed->feedUrl = (string) $feedData->link[1]['href'];
        $feed->items = self::_getAtomItems($feedData->entry);

        return $feed;
    }

    /**
     * @param object $entries
     * @return ItemObject[]
     */
    private static function _getAtomItems(object $entries): array
    {
        $items = [];
        foreach ($entries as $entry) {
            $item = new ItemObject();
            $item->id = (string) $entry->id;
            $item->title = (string) $entry->title;
            $item->description = (string) $entry->summary;
            $item->content = (string) $entry->content;
            $item->authorName = (string) $entry->author->name;
            $item->authorEmail = (string) $entry->author->email;
            $item->updated = (string) $entry->updated;
            $item->published = (string) $entry->published;
            $item->itemUrl = $entry->link[0] ? (string) $entry->link[0]['href'] : NULL;
            $item->imageUrl = $entry->link[2] ? (string) $entry->link[1]['href'] : NULL;
            $item->imageType = $entry->link1[1] ? (string) $entry->link[1]['type'] : NULL;
            $item->imageTitle = $entry->link[2] ? (string) $entry->link[1]['title'] : NULL;
            $items[] = $item;
        }
        return $items;
    }


    /**
     * @param string $url
     * @return \Timvandendries\PhpRssParser\objects\FeedObject
     */
    public static function getRSSFeedByUrl(string $url): FeedObject
    {
        $feedData = @simplexml_load_file($url);
        if ($feedData === false) {
            return new FeedObject();
        }

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
            $item = new ItemObject();
            $item->id = (string) $entry->id;
            $item->title = (string) $entry->title;
            $item->description = $entry->description->p ? (string) $entry->description->p : (string) $entry->description;
            $item->content = (string) $entry->content;
            $item->authorName = (string) $entry->author->name;
            $item->authorEmail = (string) $entry->author->email;
            $item->updated = (string) $entry->updated;
            $item->published = (string) $entry->pubDate;
            $item->itemUrl = (string) $entry->guid;
            $item->imageUrl = (string) $entry->enclosure['url'];
            $item->imageType = (string) $entry->enclosure['type'];
            $item->imageTitle = (string) $entry->enclosure['title'];

            $items[] = $item;
        }

        return $items;
    }
}
