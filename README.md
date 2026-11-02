# php-rss-parser

Read RSS and Atom feeds in PHP, and show them on any web page as a block of links with a single `<div>`.

```
┌──────────────────────────────────────────────┐
│ • NOS Nieuws                                 │
├──────────────────────────────────────────────┤
│ 21:55  Gelderland deels op slot voor nieuw…  │
│ 21:47  In Florida gecrashte privéjet verlo…  │
│ 01-11  Coalitiepartijen onderling eens ove…  │
└──────────────────────────────────────────────┘
```

- Reads RSS 0.9x/2.0, RSS 1.0 (RDF), Atom 1.0 and Atom 0.3, and detects which one a feed uses.
- Safe with URLs from user input: refuses private and internal addresses (SSRF), and limits size, time and redirects.
- Reports feed problems in `$feed->error` instead of throwing exceptions.
- Comes with a feed block for your pages: a small script, a stylesheet and a JSON endpoint with a cache. No build step and no other libraries needed.

## Requirements

- PHP 8.4.27 or newer in 8.4, or 8.5.12 or newer
- The `curl`, `dom` and `simplexml` extensions
- Optional: the `intl` extension, to fetch feeds from internationalized domain names such as bücher.de

## Installation

```bash
composer require timvandendries/php-rss-parser
```

## Quick start: a feed block on your page

You need two things: a PHP file that serves the feeds, and a `<div>` for each feed on your page.

**1. Create the endpoint**, for example `public/rss.php`, and list the feeds your pages may show:

```php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Timvandendries\PhpRssParser\FeedEndpoint;

(new FeedEndpoint(allowedFeeds: [
    'https://feeds.nos.nl/nosnieuwsalgemeen',
    'https://feeds.feedburner.com/tweakers/mixed',
]))->handle();
```

Point the `require` at your `vendor/autoload.php`.

**2. Add the block to your page.** Give it a feed with `data-rss-feed`:

```html
<link rel="stylesheet" href="/rss.php?asset=css">

<div data-rss-feed="https://feeds.nos.nl/nosnieuwsalgemeen"></div>

<script src="/rss.php?asset=js"></script>
```

That's it. The script finds every element with `data-rss-feed` and fetches the feed from `rss.php`. It shows the feed's title (a link to the site) and one row per item: the time for items from today, otherwise the date (`01-11`), followed by the title, which links to the article in a new tab.

The endpoint also serves the script and the stylesheet (`?asset=js` and `?asset=css`), so you don't have to copy anything out of `vendor/`. After a `composer update`, browsers pick up the new version at once.

## A dashboard with several feeds

Every block is a separate `<div>`. Put them in a grid and give them a height. The list then scrolls inside the block:

```html
<link rel="stylesheet" href="/rss.php?asset=css">
<style>
    .feeds {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 16px;
    }
    .feeds .rss-block {
        height: 360px;
    }
</style>

<div class="feeds">
    <div data-rss-feed="https://feeds.nos.nl/nosnieuwsalgemeen" data-rss-limit="15" data-rss-refresh="300"></div>
    <div data-rss-feed="https://feeds.nos.nl/nosnieuwstech" data-rss-title="NOS Tech"></div>
    <div data-rss-feed="https://feeds.feedburner.com/tweakers/mixed" class="rss-block--dark"></div>
</div>

<script src="/rss.php?asset=js"></script>
```

Each of these feeds must be in the endpoint's `allowedFeeds`, written exactly as in `data-rss-feed`.

## The block

### Options

Set the options as attributes on the block:

| Attribute           | Default                              | What it does                                                                               |
|---------------------|--------------------------------------|--------------------------------------------------------------------------------------------|
| `data-rss-feed`     | required                             | The feed's URL. The endpoint must allow it.                                                |
| `data-rss-limit`    | all, up to the endpoint's `maxItems` | How many items to show.                                                                    |
| `data-rss-title`    | the feed's title                     | The title in the block's header.                                                           |
| `data-rss-dates`    | `true`                               | `false` hides the time and date in front of the items.                                     |
| `data-rss-refresh`  | off                                  | Reloads the feed every this many seconds. If a reload fails, the items already shown stay. |
| `data-rss-endpoint` | see below                            | The URL of the endpoint.                                                                   |

Without `data-rss-endpoint`, a block uses `data-endpoint` from the `<script>` tag. Without that, it uses the endpoint the script was loaded from (`/rss.php?asset=js` gives `/rss.php`), and as a last resort `/rss.php`.

### From JavaScript

The script makes `window.RssBlock` available. Use it for blocks you add after the page has loaded, or to set the options in code:

```js
// Every element with data-rss-feed in the container that isn't a block yet
RssBlock.renderAll(container);

// One element, with the same options as the attributes (they take precedence over them)
await RssBlock.render(document.querySelector('#news'), {
    feed: 'https://feeds.nos.nl/nosnieuwsalgemeen',
    limit: 10,
    title: 'Nieuws',
    dates: true,
    refresh: 300,
});
```

Calling `render()` again on the same element replaces the block, for example to show another feed.

### Language

The blocks show "Loading…", an error message and an "empty feed" message. These texts are in Dutch when the page says `<html lang="nl">` or the script tag has `data-lang="nl"`. On other pages they are in English. For another language, set the texts in a script directly after `rss-block.js` (and don't give that script `defer` or `async`):

```html
<script src="/rss.php?asset=js"></script>
<script>
    RssBlock.text = {
        loading: 'Chargement…',
        error: 'Impossible de charger ce flux.',
        empty: 'Ce flux est vide.',
    };
</script>
```

### Styling

The stylesheet uses CSS custom properties. Set them on `.rss-block`, or on one block only:

```css
.rss-block {
    --rss-block-accent: #0a7cff;  /* dot, hover color and the bar in front of the hovered item; default RSS orange */
    --rss-block-font-size: 15px;
    --rss-block-radius: 8px;
    /* also: --rss-block-bg, --rss-block-fg, --rss-block-muted, --rss-block-line, --rss-block-hover */
}
```

Add `rss-block--dark` to a block for dark colors, or `rss-block--auto` to follow the visitor's light or dark mode setting. For further styling, these are the elements of a block:

```
.rss-block                    the <div> itself
  .rss-block__header
    .rss-block__name          feed title, a link to the site when the feed has one
  .rss-block__items           <ul>
    .rss-block__item          <li>
      .rss-block__link        <a>, or <span> for items without a valid link
        .rss-block__date      <time>
        .rss-block__title
  .rss-block__message         loading, error or empty
```

### Serving the files yourself

If you would rather serve `rss-block.js` and `rss-block.css` as static files, or include them in your own build, copy them from the package. Copy them again after every update:

```bash
cp vendor/timvandendries/php-rss-parser/assets/rss-block.* public/
```

```html
<link rel="stylesheet" href="/rss-block.css">
<script src="/rss-block.js" data-endpoint="/rss.php"></script>
```

## The endpoint

```php
new FeedEndpoint(
    allowedFeeds: ['https://feeds.nos.nl/nosnieuwsalgemeen'],
    cacheDir: __DIR__ . '/../storage/rss-cache',
    cacheTtl: 600,
    maxItems: 50,
    allowPrivateNetworks: false,
);
```

| Option                 | Default                                  | What it does                                                                                     |
|------------------------|------------------------------------------|--------------------------------------------------------------------------------------------------|
| `allowedFeeds`         | required                                 | The feed URLs that blocks may show. `null` allows every public feed (see below).                 |
| `cacheDir`             | `php-rss-parser` in the system temp dir  | Where fetched feeds are stored.                                                                  |
| `cacheTtl`             | `600`                                    | Seconds a fetched feed is served from the cache before it is fetched again. `0` turns the cache off. |
| `maxItems`             | `50`                                     | The most items sent for a feed, whatever `data-rss-limit` asks for.                              |
| `allowPrivateNetworks` | `false`                                  | Allows feeds on your own network, such as intranet feeds. Only possible with a list of `allowedFeeds`. |

### Requests and responses

`GET rss.php?feed=<url>&limit=<n>` returns what a block shows of the feed:

```json
{
    "title": "NOS Nieuws",
    "siteUrl": "https://nos.nl",
    "items": [
        {
            "title": "Gelderland deels op slot voor nieuwe aansluitingen stroomnet: 'Bittere pil'",
            "itemUrl": "https://nos.nl/l/2637801",
            "published": "2026-11-02T21:55:22+01:00",
            "updated": null
        }
    ]
}
```

- Links are absolute `http(s)` URLs or `null`, so a `javascript:` link from a feed never ends up on your page.
- Dates are in ISO 8601, or `null` when the feed has none or they can't be read.
- Descriptions and content are left out, because they can contain the feed's own HTML. Use the PHP API if you need them.

When something goes wrong, the endpoint returns JSON with an error message, `{"error": "…"}`:

| Status | When                                                     |
|--------|----------------------------------------------------------|
| 400    | The `feed` parameter is missing.                         |
| 403    | The feed isn't in `allowedFeeds`.                        |
| 404    | `?asset=` names a file that doesn't exist.               |
| 502    | The feed can't be fetched and there is no cached copy.   |

The browser only gets the message "Could not load the feed". The reason goes to PHP's `error_log()`, because it can name hosts and addresses on your server's network.

### Cache

A feed is fetched at most once per `cacheTtl`, however many visitors view the page. If fetching fails after that, for example because the feed's server is down, the endpoint keeps serving the last copy it has. A list from an hour ago is better than an error.

### Allowing every feed

With `allowedFeeds: null`, a block can show any public feed without a change to `rss.php`. Note that anyone can then use your endpoint to fetch feeds, and every feed they fetch takes up space in the cache. Private and internal addresses stay blocked. Prefer a list when you know the feeds.

### In Laravel or another framework

`handle()` sends the response directly. In a framework, use `respond()` to get the response and send it the framework's way:

```php
use Illuminate\Http\Request;
use Timvandendries\PhpRssParser\FeedEndpoint;

Route::get('/rss', function (Request $request) {
    $response = (new FeedEndpoint(
        allowedFeeds: ['https://feeds.nos.nl/nosnieuwsalgemeen'],
        cacheDir: storage_path('framework/cache/rss'),
    ))->respond($request->query(), (string) $request->header('If-None-Match'));

    return response($response['body'], $response['status'], $response['headers']);
});
```

```html
<script src="/rss?asset=js"></script>
```

## Using the parser in PHP

```php
use Timvandendries\PhpRssParser\RssParser;

$feed = RssParser::getFeed('https://feeds.nos.nl/nosnieuwsalgemeen');

if ($feed->hasError()) {
    error_log($feed->error);
} else {
    echo htmlspecialchars($feed->title);
    foreach ($feed->items as $item) {
        echo htmlspecialchars($item->title);
    }
}
```

| Method                                                | Reads                                                     |
|-------------------------------------------------------|-----------------------------------------------------------|
| `RssParser::getFeed($url, $allowPrivateNetworks = false)`         | An RSS or Atom feed from an `http(s)` URL       |
| `RssParser::getRSSFeedByUrl($url, $allowPrivateNetworks = false)` | An RSS feed from a URL                          |
| `RssParser::getAtomFeedByUrl($url, $allowPrivateNetworks = false)`| An Atom feed from a URL                         |
| `RssParser::getFeedFromFile($path)`                   | An RSS or Atom feed from a local file                     |
| `RssParser::getRSSFeedFromFile($path)`                | An RSS feed from a local file                             |
| `RssParser::getAtomFeedFromFile($path)`               | An Atom feed from a local file                            |

### Example: the block in plain PHP

The same block without JavaScript, rendered on the server. It uses the block's class names, so `rss-block.css` styles it as well:

```php
<?php

use Timvandendries\PhpRssParser\RssParser;

$feed = RssParser::getFeed('https://feeds.nos.nl/nosnieuwsalgemeen');

$text = fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES);
// Only http(s) links: htmlspecialchars() doesn't stop a javascript: URL
$link = fn (?string $url): ?string => preg_match('~^https?://~i', (string) $url) === 1 ? $text($url) : null;
?>
<div class="rss-block">
    <div class="rss-block__header">
        <a class="rss-block__name" href="<?= $link($feed->siteUrl) ?? '#' ?>"><?= $text($feed->title) ?></a>
    </div>
    <?php if ($feed->hasError()): ?>
        <p class="rss-block__message">This feed could not be loaded.</p>
    <?php else: ?>
        <ul class="rss-block__items">
            <?php foreach (array_slice($feed->items, 0, 10) as $item): ?>
                <li class="rss-block__item">
                    <a class="rss-block__link" href="<?= $link($item->itemUrl) ?? '#' ?>" target="_blank" rel="noopener noreferrer">
                        <span class="rss-block__title"><?= $text($item->title) ?></span>
                    </a>
                </li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
</div>
```

This fetches the feed on every page view, which takes as long as the feed's server needs to respond. For pages with visitors, use the endpoint (it has a cache) or fetch feeds in the background.

### Example: fetching feeds in the background

A dashboard with many feeds is best updated outside the page view, for example in a Laravel queue job that stores the items:

```php
use App\Models\Feed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Timvandendries\PhpRssParser\RssParser;

class RefreshFeed implements ShouldQueue
{
    use Queueable;

    public function __construct(public Feed $feed) {}

    public function handle(): void
    {
        $result = RssParser::getFeed($this->feed->feed_url);
        if ($result->hasError()) {
            Log::warning("Feed {$this->feed->feed_url} failed: {$result->error}");
            return;  // keep the items from the last time it worked
        }

        // The items become JSON objects with the fields listed below
        $this->feed->update(['items' => json_encode($result->items)]);
    }
}
```

### What a feed contains

`FeedObject`:

| Property      | Contains                                                    |
|---------------|-------------------------------------------------------------|
| `title`       | The feed's title                                            |
| `siteUrl`     | The website the feed belongs to                             |
| `feedUrl`     | The feed's own URL                                          |
| `description` | RSS only, `null` for Atom                                   |
| `language`, `copyright`, `webmaster` | RSS only, `null` for Atom            |
| `id`          | Atom only, `null` for RSS                                   |
| `updated`, `published` | Dates as the feed writes them                      |
| `items`       | A list of `ItemObject`s; `null` when there is an error      |
| `error`       | Why the feed couldn't be read, or `null`                    |

`ItemObject`:

| Property                               | Contains                                                         |
|----------------------------------------|------------------------------------------------------------------|
| `title`                                | The item's title                                                 |
| `itemUrl`                              | The link to the article, or `null` when there is none            |
| `description`, `content`               | Summary and full text; can contain HTML                          |
| `published`, `updated`                 | Dates as the feed writes them (RFC 822 for RSS, RFC 3339 for Atom) |
| `authorName`, `authorEmail`            | The author, when the feed gives one                              |
| `imageUrl`, `imageType`, `imageTitle`  | An image enclosure; `null` for Atom items without one            |
| `id`                                   | The item's ID                                                    |

### Errors

Everything that can go wrong with a feed, such as an unreachable server, an HTTP error, invalid XML or a document that isn't a feed, ends up in `$feed->error`. No exception is thrown. An exception from this library (a `LogicException`) means a bug in the library, not a problem with the feed.

### Security

- **Escape everything.** All values come from the feed. Put them on a page with `htmlspecialchars()`, and check that links start with `http://` or `https://`. `description` and `content` can contain HTML with scripts: pass them through a sanitizer such as [HTML Purifier](http://htmlpurifier.org/) before you show them.
- **Internal addresses are blocked.** By default, the URL methods refuse feeds on private, loopback and other reserved addresses, and they don't use proxies. This makes it safe to fetch URLs that visitors enter. Only pass `$allowPrivateNetworks = true` for URLs you trust, for example intranet feeds, or when your server can only reach the internet through a proxy.
- **Limits.** A feed may be 10 MB at most. Fetching may take 30 seconds at most, including at most 5 redirects.
- **Local files.** The `*FromFile()` methods read any file PHP can read, so only pass them paths you trust.

## Development

```bash
composer test           # tests, without internet access
composer test-network   # the tests that need internet access
composer lint           # code style check (Pint)
composer format         # fix the code style
composer analyse        # static analysis (PHPStan)
```

## License

MIT, see [LICENSE](LICENSE).
