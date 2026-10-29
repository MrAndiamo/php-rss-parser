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
 *
 * All values except $error come from the feed and are untrusted plain text (Atom titles too):
 * escape them with htmlspecialchars() before putting them on a page.
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

    public function hasError(): bool
    {
        return $this->error !== null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->{$name} = $value;
    }

    public function __get(string $name): mixed
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
    ];
}
