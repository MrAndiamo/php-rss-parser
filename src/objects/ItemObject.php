<?php

declare(strict_types = 1);

namespace Timvandendries\PhpRssParser\objects;

/**
 * @property string $id
 * @property string $title
 * @property string $description
 * @property string $content
 * @property string $authorName
 * @property string $authorEmail
 * @property string $updated
 * @property string $published
 * @property string $itemUrl
 * @property string $imageUrl
 * @property string $imageType
 * @property string $imageTitle
 *
 */
class ItemObject {

    protected array $_fields = [
        'id'            => 'id',
        'title'         => 'title',
        'description'   => 'description',
        'content'       => 'content',
        'authorName'    => 'authorName',
        'authorEmail'   => 'authorEmail',
        'updated'       => 'updated',
        'published'     => 'published',
        'itemUrl'       => 'itemUrl',
        'imageUrl'      => 'imageUrl',
        'imageType'     => 'imageUrl',
        'imageTitle'    => 'imageTitle'
    ];

    protected array $_field_types = [
        'id'            => ['type' => 'string', 'nullable' => FALSE],
        'title'         => ['type' => 'string', 'nullable' => FALSE],
        'description'   => ['type' => 'string', 'nullable' => FALSE],
        'content'       => ['type' => 'string', 'nullable' => FALSE],
        'authorName'    => ['type' => 'string', 'nullable' => FALSE],
        'authorEmail'   => ['type' => 'string', 'nullable' => FALSE],
        'updated'       => ['type' => 'string', 'nullable' => FALSE],
        'published'     => ['type' => 'string', 'nullable' => FALSE],
        'itemUrl'       => ['type' => 'string', 'nullable' => FALSE],
        'imageUrl'      => ['type' => 'string', 'nullable' => FALSE],
        'imageType'     => ['type' => 'string', 'nullable' => FALSE],
        'imageTitle'    => ['type' => 'string', 'nullable' => FALSE]
    ];

}
