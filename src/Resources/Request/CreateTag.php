<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Relationship;
use nickdnk\Klaviyo\Resources\Shared\Tag;
use nickdnk\Klaviyo\Resources\Shared\TagGroup;

/**
 * Creates a tag (POST /api/tags). Without `$tagGroupId` the tag lands in the account's
 * default tag group; an account holds at most 500 tags.
 *
 * @property string $name
 */
class CreateTag extends Tag
{

    public function __construct(string $name, ?string $tagGroupId = null)
    {
        parent::__construct();
        $this->name = $name;
        if ($tagGroupId !== null) {
            $this->addRelationship('tag-group', new Relationship(new TagGroup($tagGroupId)));
        }
    }

}
