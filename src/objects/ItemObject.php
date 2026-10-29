<?php

declare(strict_types=1);

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
class ItemObject extends \stdClass
{
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

    /** @var array<string, array{type: string, nullable: bool}> */
    protected array $_field_types = [
        'id'            => ['type' => 'string', 'nullable' => true],
        'title'         => ['type' => 'string', 'nullable' => true],
        'description'   => ['type' => 'string', 'nullable' => true],
        'content'       => ['type' => 'string', 'nullable' => true],
        'authorName'    => ['type' => 'string', 'nullable' => true],
        'authorEmail'   => ['type' => 'string', 'nullable' => true],
        'updated'       => ['type' => 'string', 'nullable' => true],
        'published'     => ['type' => 'string', 'nullable' => true],
        'itemUrl'       => ['type' => 'string', 'nullable' => true],
        'imageUrl'      => ['type' => 'string', 'nullable' => true],
        'imageType'     => ['type' => 'string', 'nullable' => true],
        'imageTitle'    => ['type' => 'string', 'nullable' => true]
    ];

}
