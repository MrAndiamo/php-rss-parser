<?php

declare(strict_types = 1);

namespace Timvandendries\PhpRssParser\objects;

use Timvandendries\PhpRssParser\objects\ItemObject;

/**
 * @property string $id
 * @property string $title
 * @property string $updated
 * @property string $published
 * @property string $siteUrl
 * @property string $feedUrl
 * @property ItemObject $items
 */
class FeedObject {

    protected array $_fields = [
        'id'            => 'id',
        'title'         => 'title',
        'updated'       => 'updated',
        'published'     => 'published',
        'siteUrl'       => 'siteUrl',
        'feedUrl'       => 'imageUrl',
        'items'         => 'imageUrl',
    ];

    protected array $_field_types = [
        'id'            => ['type' => 'string', 'nullable' => FALSE],
        'title'         => ['type' => 'string', 'nullable' => FALSE],
        'updated'       => ['type' => 'string', 'nullable' => FALSE],
        'published'     => ['type' => 'string', 'nullable' => FALSE],
        'siteUrl'       => ['type' => 'string', 'nullable' => FALSE],
        'feedUrl'       => ['type' => 'string', 'nullable' => FALSE],
        'items'         => ['type' => ItemObject::class, 'nullable' => FALSE],
    ];
}