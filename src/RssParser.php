<?php

declare(strict_types = 1);

namespace Timvandendries\PhpRssParser;

use Timvandendries\PhpRssParser\objects\FeedObject;
use Timvandendries\PhpRssParser\objects\ItemObject;

class RssParser {


    public const RSS_1_0_PATTERN = '{xml-stylesheet href="/public/style.xsl" type="text/xsl"}';
    public const RSS_2_0_PATTERN = '{rss version="2.0"}';
    public const RSS_1_0_ATOM_PATTERN = '{http://www.w3.org/2005/Atom}';

    /**
     * @param string $url
     * @return FeedObject
     */
    public function getFeed(string $url){

        $cUrl = curl_init($url);
        curl_setopt($cUrl, CURLOPT_RETURNTRANSFER, true);
        $data = curl_exec($cUrl);
        curl_close($cUrl);
        if(preg_match(self::RSS_1_0_ATOM_PATTERN, $data)) {
            $feed = $this->getAtomFeedByUrl($url);
        } elseif(preg_match(self::RSS_2_0_PATTERN, $data)) {
            $feed = $this->getRSS2FeedByUrl($url);
        } elseif(preg_match(self::RSS_1_0_PATTERN, $data)) {
            $feed = $this->getRSS1FeedByUrl($url);
        } else {
            $feed = new FeedObject();
        }

        return $feed;

    }


    /**
     * @param string $url
     * @return \Timvandendries\PhpRssParser\objects\FeedObject
     */
    public function getAtomFeedByUrl(string $url) : FeedObject {

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
        $feed->items = $this->_getAtomItems($feedData->entry);

        return $feed;

    }

    /**
     * @param object $entries
     * @return ItemObject[]
     */
    private function _getAtomItems(object $entries) : array {
        $items = [];
        foreach($entries as $entry) {
            $item = new ItemObject();
            $item->id = (string) $entry->id;
            $item->title = (string) $entry->title;
            $item->description = (string) $entry->summary;
            $item->content = (string) $entry->content;
            $item->authorName = (string) $entry->author->name;
            $item->authorEmail = (string) $entry->author->email;
            $item->updated = (string) $entry->updated;
            $item->published = (string) $entry->published;
            $item->itemUrl = (string) $entry->link[0]['href'];
            $item->imageUrl = (string) $entry->link[1]['href'];
            $item->imageType = (string) $entry->link[1]['type'];
            $item->imageTitle = (string) $entry->link[1]['title'];
            $items[] = $item;
        }
        return $items;
    }


    /**
     * @param string $url
     * @return \Timvandendries\PhpRssParser\objects\FeedObject
     */
    public function getRSS1FeedByUrl(string $url) : FeedObject {
        $feedData = simplexml_load_file($url);

        $feed = new FeedObject();
        $feed->id = NULL;
        $feed->title = (string) $feedData->channel->title;
        $feed->description = (string) $feedData->channel->description;
        $feed->language = (string) $feedData->channel->language;
        $feed->copyright = (string) $feedData->channel->copyright;
        $feed->webmaster = (string) $feedData->channel->webmaster;
        $feed->updated = (string) $feedData->channel->updated;
        $feed->published = (string) $feedData->channel->pubDate;
        $feed->siteUrl = (string) $feedData->channel->link;
        $feed->feedUrl = $url;
        $feed->items = $this->_getRSS1Items($feedData->channel->item);

        return $feed;
    }


    /**
     * @param object $entries
     * @return ItemObject[]
     */
    private function _getRSS1Items(object $entries) : array {
        $items = [];
        foreach($entries as $entry) {
            $item = new ItemObject();
            $item->id = (string) $entry->id;
            $item->title = (string) $entry->title;
            $item->description = (string) $entry->description;
            $item->content = (string) $entry->description;
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



    public function getRSS2FeedByUrl(string $url) {
        return 'build RSS 2.0 Feed';
    }




}
