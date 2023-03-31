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

    private ?string $id = NULL;
    private ?string $title = NULL;
    private ?string $description = NULL;
    private ?string $content = NULL;
    private ?string $authorName = NULL;
    private ?string $authorEmail = NULL;
    private ?string $updated = NULL;
    private ?string $published = NULL;
    private ?string $itemUrl = NULL;
    private ?string $imageUrl = NULL;
    private ?string $imageType = NULL;
    private ?string $imageTitle = NULL;

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
        'id'            => ['type' => 'string', 'nullable' => TRUE],
        'title'         => ['type' => 'string', 'nullable' => TRUE],
        'description'   => ['type' => 'string', 'nullable' => TRUE],
        'content'       => ['type' => 'string', 'nullable' => TRUE],
        'authorName'    => ['type' => 'string', 'nullable' => TRUE],
        'authorEmail'   => ['type' => 'string', 'nullable' => TRUE],
        'updated'       => ['type' => 'string', 'nullable' => TRUE],
        'published'     => ['type' => 'string', 'nullable' => TRUE],
        'itemUrl'       => ['type' => 'string', 'nullable' => TRUE],
        'imageUrl'      => ['type' => 'string', 'nullable' => TRUE],
        'imageType'     => ['type' => 'string', 'nullable' => TRUE],
        'imageTitle'    => ['type' => 'string', 'nullable' => TRUE]
    ];

}
