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
 * @property null|string $itemUrl NULL when the item has no link
 * @property null|string $imageUrl NULL for Atom items without an image enclosure
 * @property null|string $imageType NULL for Atom items without an image enclosure
 * @property null|string $imageTitle NULL for Atom items without an image enclosure
 *
 * All values come from the feed and are untrusted. $description and $content can hold HTML that is
 * returned as the feed has it, including scripts and event handlers: run them through an HTML sanitizer
 * such as HTML Purifier before putting them on a page. Atom titles are plain text; escape them, like
 * every other text field, with htmlspecialchars() before output.
 */
class ItemObject extends \stdClass {

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
        'imageType'     => 'imageType',
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
