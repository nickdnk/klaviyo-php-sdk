<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\TagGroup;

/**
 * Renames a tag group (PATCH /api/tag-groups/{id}). `name` is the only writable attribute
 * and Klaviyo requires it; `exclusive` and `default` are fixed at creation.
 *
 * @property string        $name
 * @property string[]|null $return_fields  request-only: attribute names Klaviyo echoes back
 */
class UpdateTagGroup extends TagGroup
{

    public function __construct(string $id)
    {
        parent::__construct($id);
    }

}
