<?php


namespace nickdnk\Klaviyo\Resources\Shared;

/**
 * @property string|null $name
 */
class Tag extends IdentifiableResource
{
    public static function type(): string
    {

        return 'tag';
    }
}
