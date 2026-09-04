<?php


namespace nickdnk\Klaviyo\Resources\Shared;

/**
 * @property string|null $name
 */
class TagGroup extends IdentifiableResource
{
    public static function type(): string
    {

        return 'tag-group';
    }
}
