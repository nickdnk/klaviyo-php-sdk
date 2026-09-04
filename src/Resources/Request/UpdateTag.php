<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Tag;

/**
 * Renames a tag (PATCH /api/tags/{id}). `name` is the only writable attribute and Klaviyo
 * requires it; a tag cannot be moved between tag groups.
 *
 * @property string $name
 */
class UpdateTag extends Tag
{

    public function __construct(string $id)
    {
        parent::__construct($id);
    }

}
