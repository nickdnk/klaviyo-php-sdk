<?php


namespace nickdnk\Klaviyo\Resources\Shared;

/**
 * @property string|null $name
 * @property string|null $description
 * @property string|null $endpoint_url
 */
class Webhook extends IdentifiableResource
{
    public static function type(): string
    {

        return 'webhook';
    }
}
