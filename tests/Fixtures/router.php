<?php

/**
 * Router for the PHP built-in web server that FeedServer starts for the tests.
 *
 *   /feeds/<name>.xml            a file from Fixtures/feeds
 *   /with-bom/<name>.xml         the same, with a UTF-8 BOM in front
 *   /with-whitespace/<name>.xml  the same, with a newline and spaces in front
 *   /redirect?to=<url>           302 to <url>
 *   /redirect-loop               302 to itself
 *   /redirect-no-location        302 without a Location header
 *   /status/<code>               an empty response with that status code
 *   /too-large                   an 11 MB feed
 *   /gzip-bomb                   a gzip response that expands to 20 MB
 *   /gzip                        a small gzip-compressed feed
 *   /user-agent                  an RSS feed whose title is the request's User-Agent
 */

declare(strict_types=1);

/** Request values are strings in practice, but PHP types $_SERVER and $_GET entries as mixed. */
$stringValue = fn (mixed $value, string $default = ''): string => is_string($value) ? $value : $default;

$path = (string) parse_url($stringValue($_SERVER['REQUEST_URI'] ?? null, '/'), PHP_URL_PATH);

$serveFeed = function (string $name, string $prefix = ''): void {
    $file = __DIR__ . '/feeds/' . $name;
    if (preg_match('~^[a-z0-9-]+\.xml$~', $name) !== 1 || !is_file($file)) {
        http_response_code(404);
        echo 'Not found';
        return;
    }
    header('Content-Type: application/xml; charset=utf-8');
    echo $prefix, file_get_contents($file);
};

if (preg_match('~^/(feeds|with-bom|with-whitespace)/([^/]+)$~', $path, $match) === 1) {
    $prefixes = ['feeds' => '', 'with-bom' => "\xEF\xBB\xBF", 'with-whitespace' => "\n   \t"];
    $serveFeed($match[2], $prefixes[$match[1]]);
    return true;
}

if (preg_match('~^/status/(\d{3})$~', $path, $match) === 1) {
    http_response_code((int) $match[1]);
    return true;
}

switch ($path) {
    case '/redirect':
        header('Location: ' . $stringValue($_GET['to'] ?? null, '/'), true, 302);
        return true;

    case '/redirect-loop':
        header('Location: /redirect-loop', true, 302);
        return true;

    case '/redirect-no-location':
        http_response_code(302);
        echo '<html>Moved, somewhere</html>';
        return true;

    case '/too-large':
        header('Content-Type: application/xml');
        echo '<rss><channel><title>', str_repeat('a', 11 * 1024 * 1024), '</title></channel></rss>';
        return true;

    case '/gzip-bomb':
        header('Content-Type: application/xml');
        header('Content-Encoding: gzip');
        echo gzencode('<rss><channel><title>' . str_repeat('a', 20 * 1024 * 1024) . '</title></channel></rss>', 9);
        return true;

    case '/gzip':
        header('Content-Type: application/xml');
        header('Content-Encoding: gzip');
        echo gzencode('<rss><channel><title>Compressed</title></channel></rss>');
        return true;

    case '/user-agent':
        header('Content-Type: application/xml');
        echo '<rss><channel><title>', htmlspecialchars($stringValue($_SERVER['HTTP_USER_AGENT'] ?? null)), '</title></channel></rss>';
        return true;
}

http_response_code(404);
echo 'Not found';
return true;
