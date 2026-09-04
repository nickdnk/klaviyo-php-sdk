<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\TagGroup;

/**
 * Creates a tag group (POST /api/tag-groups); an account holds at most 50. Left unset,
 * `exclusive` defaults to false server-side: a resource may then carry several tags from
 * the group, where an exclusive group allows exactly one.
 *
 * @property string    $name
 * @property bool|null $exclusive
 */
class CreateTagGroup extends TagGroup
{

    public function __construct(string $name, ?bool $exclusive = null)
    {
        parent::__construct();
        $this->name = $name;
        $this->exclusive = $exclusive;
    }

}
