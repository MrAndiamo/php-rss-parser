<?php

declare(strict_types = 1);

namespace Timvandendries\PhpRssParser\objects;

use Timvandendries\PhpRssParser\objects\ItemObject;

/**
 * @property string $id
 * @property string $title
 * @property string $description
 * @property string $language
 * @property string $copyright
 * @property string $webmaster
 * @property string $updated
 * @property string $published
 * @property string $siteUrl
 * @property string $feedUrl
 * @property ItemObject[] $items
 */
class FeedObject {

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