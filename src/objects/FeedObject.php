<?php

declare(strict_types = 1);

namespace Timvandendries\PhpRssParser\objects;

use Timvandendries\PhpRssParser\objects\ItemObject;

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
 * @property null|ItemObject[] $items
 */
class FeedObject extends \stdClass {

    public ?string $id = NULL;
    public ?string $title = NULL;
    public ?string $description = NULL;
    public ?string $language = NULL;
    public ?string $copyright = NULL;
    public ?string $webmaster = NULL;
    public ?string $updated = NULL;
    public ?string $published = NULL;
    public ?string $siteUrl = NULL;
    public ?string $feedUrl = NULL;
    public ?array $items = NULL;

    public function __set(string $name, $value): void {
        $this->{$name} = $value;
    }

    public function __get(string $name) {
        return $this->{$name};
    }

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
        'feedUrl'       => 'imageUrl',
        'items'         => 'items',
    ];

    protected array $_field_types = [
        'id'            => ['type' => 'string', 'nullable' => TRUE],
        'title'         => ['type' => 'string', 'nullable' => TRUE],
        'description'   => ['type' => 'string', 'nullable' => TRUE],
        'language'      => ['type' => 'string', 'nullable' => TRUE],
        'copyright'     => ['type' => 'string', 'nullable' => TRUE],
        'webmaster'     => ['type' => 'string', 'nullable' => TRUE],
        'updated'       => ['type' => 'string', 'nullable' => TRUE],
        'published'     => ['type' => 'string', 'nullable' => TRUE],
        'siteUrl'       => ['type' => 'string', 'nullable' => TRUE],
        'feedUrl'       => ['type' => 'string', 'nullable' => TRUE],
        'items'         => ['type' => [ItemObject::class], 'nullable' => TRUE],
    ];
}