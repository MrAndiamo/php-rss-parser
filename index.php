<?php

use Timvandendries\PhpRssParser\RssParser;

require_once 'vendor/autoload.php';

$parser = new RssParser();

echo 'Geentijl<br>';
var_dump($parser->getFeed('https://www.geenstijl.nl/feeds/recent.atom'));

echo '<br><br>NOS<br>';
$feed = ($parser->getFeed('https://feeds.nos.nl/nosnieuwsalgemeen'));
$feed->items[0]->imageUrl;
//echo '<br><br>Fok<br>';
//var_dump($parser->getFeed('https://rss.fok.nl/feeds/nieuws'));