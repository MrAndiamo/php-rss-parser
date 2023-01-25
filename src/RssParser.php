<?php

declare(strict_types = 1);

namespace Timvandendries\PhpRssParser;

class RssParser {


    public const RSS_1_0_PATTERN = '{xml-stylesheet href="/public/style.xsl" type="text/xsl"}';
    public const RSS_2_0_PATTERN = '{rss version="2.0"}';
    public const RSS_1_0_ATOM_PATTERN = '{http://www.w3.org/2005/Atom}';


    public function getFeedByUrl(string $feedUrl){

        $feed = NULL;
        $curlUrl = curl_init($feedUrl);
        curl_setopt($curlUrl, CURLOPT_RETURNTRANSFER, true);

        $data = curl_exec($curlUrl);
        if(preg_match(self::RSS_1_0_ATOM_PATTERN, $data)) {
            $feed = $this->getAtomFeedByUrl($feedUrl);
        } elseif(preg_match(self::RSS_2_0_PATTERN, $data)) {
            $feed = $this->getRSS2FeedByUrl($feedUrl);
        } elseif(preg_match(self::RSS_1_0_PATTERN, $data)) {
            $feed = $this->getRSS1FeedByUrl($feedUrl);
        } else {
            echo 'Unknown<br>';
        }

        curl_close($curlUrl);

        return $feed;


    }


    public function getAtomFeedByUrl(string $feedUrl) {

        $feedData = simplexml_load_file($feedUrl);

        $feed = new \stdClass();
        $feed->title = (string) $feedData->title;
        $feed->id = (string) $feedData->id;
        $feed->updated = (string) $feedData->updated;
        $feed->siteUrl = (string) $feedData->link[0]['href'];
        $feed->feedUrl = (string) $feedData->link[1]['href'];

        $feed->items = $this->_getAtomItems($feedData->entry);
        return $feed;

    }


    private function _getAtomItems(object $entries) : array {
        var_dump(gettype($entries));
        $items = [];
        foreach($entries as $entry) {

            $item = new \stdClass();
            


            $items[] = $item;
        }
        return $items;
    }


    public function getRSS1FeedByUrl(string $feedUrl) {
        return 'build RSS 1.0 Feed';
    }


    public function getRSS2FeedByUrl(string $feedUrl) {
        return 'build RSS 2.0 Feed';
    }




}
