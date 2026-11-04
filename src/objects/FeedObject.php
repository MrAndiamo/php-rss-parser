<?php

declare(strict_types=1);

namespace Timvandendries\PhpRssParser\objects;

/**
 * @property null|string $id
 * @property null|string $title
 * @property null|string $description
 * @property null|string $language
 * @property null|string $copyright
 * @property null|string $webmaster
 * @property null|string $updated
 * @property null|string $published
 * @property null|string $siteUrl
 * @property null|string $feedUrl
 * @property null|list<ItemObject> $items
 * @property null|string $error
 * @property bool $notModified
 * @property null|int $status
 * @property null|string $etag
 * @property null|string $lastModified
 * @property null|int $retryAfter
 * @property int $responseSize
 *
 * All values from $id to $items come from the feed and are untrusted plain text (Atom titles too):
 * escape them with htmlspecialchars() before putting them on a page.
 *
 * The fields after $error describe the HTTP response and are only set by the URL methods:
 * - $notModified: the server answered 304 Not Modified to getFeedIfChanged(); the feed has no
 *   title, items or error then, so keep what the earlier fetch returned.
 * - $status: the final HTTP status code after redirects, also for an HTTP error; null when no
 *   response came, for example when the host didn't resolve.
 * - $etag, $lastModified: the validators to pass to getFeedIfChanged() next time; null when the
 *   server sent none.
 * - $retryAfter: the seconds the server asked to wait (Retry-After, usually with 429 or 503).
 * - $responseSize: the size of the body in bytes, after decompression; 0 for 304 Not Modified.
 */
class FeedObject extends \stdClass
{
    public ?string $id = null;
    public ?string $title = null;
    public ?string $description = null;
    public ?string $language = null;
    public ?string $copyright = null;
    public ?string $webmaster = null;
    public ?string $updated = null;
    public ?string $published = null;
    public ?string $siteUrl = null;
    public ?string $feedUrl = null;
    /** @var list<ItemObject>|null */
    public ?array $items = null;
    public ?string $error = null;
    public bool $notModified = false;
    public ?int $status = null;
    public ?string $etag = null;
    public ?string $lastModified = null;
    public ?int $retryAfter = null;
    public int $responseSize = 0;

    public function hasError(): bool
    {
        return $this->error !== null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->{$name} = $value;
    }

    /**
     * No native return type: adding one would break subclasses that override __get() without it.
     *
     * @return mixed
     */
    public function __get(string $name)
    {
        return $this->{$name};
    }

    /** @var array<string, string> */
    protected array $_fields = [
        'id'            => 'id',
        'title'         => 'title',
        'description'   => 'description',
        'language'      => 'language',
        'copyright'     => 'copyright',
        'webmaster'     => 'webmaster',
        'updated'       => 'updated',
        'published'     => 'published',
        'siteUrl'       => 'siteUrl',
        'feedUrl'       => 'feedUrl',
        'items'         => 'items',
        'error'         => 'error',
        'notModified'   => 'notModified',
        'status'        => 'status',
        'etag'          => 'etag',
        'lastModified'  => 'lastModified',
        'retryAfter'    => 'retryAfter',
        'responseSize'  => 'responseSize',
    ];

    /** @var array<string, array{type: string|list<class-string>, nullable: bool}> */
    protected array $_field_types = [
        'id'            => ['type' => 'string', 'nullable' => true],
        'title'         => ['type' => 'string', 'nullable' => true],
        'description'   => ['type' => 'string', 'nullable' => true],
        'language'      => ['type' => 'string', 'nullable' => true],
        'copyright'     => ['type' => 'string', 'nullable' => true],
        'webmaster'     => ['type' => 'string', 'nullable' => true],
        'updated'       => ['type' => 'string', 'nullable' => true],
        'published'     => ['type' => 'string', 'nullable' => true],
        'siteUrl'       => ['type' => 'string', 'nullable' => true],
        'feedUrl'       => ['type' => 'string', 'nullable' => true],
        'items'         => ['type' => [ItemObject::class], 'nullable' => true],
        'error'         => ['type' => 'string', 'nullable' => true],
        'notModified'   => ['type' => 'bool', 'nullable' => false],
        'status'        => ['type' => 'int', 'nullable' => true],
        'etag'          => ['type' => 'string', 'nullable' => true],
        'lastModified'  => ['type' => 'string', 'nullable' => true],
        'retryAfter'    => ['type' => 'int', 'nullable' => true],
        'responseSize'  => ['type' => 'int', 'nullable' => false],
    ];
}
