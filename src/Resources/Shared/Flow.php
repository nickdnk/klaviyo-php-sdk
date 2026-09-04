<?php


namespace nickdnk\Klaviyo\Resources\Shared;

/**
 * @property string|null $name
 */
class Flow extends IdentifiableResource
{
    public static function type(): string
    {

        return 'flow';
    }
}
