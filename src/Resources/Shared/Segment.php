<?php


namespace nickdnk\Klaviyo\Resources\Shared;

/**
 * @property string|null $name
 */
class Segment extends IdentifiableResource
{
    public static function type(): string
    {

        return 'segment';
    }
}
