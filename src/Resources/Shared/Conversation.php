<?php


namespace nickdnk\Klaviyo\Resources\Shared;

/**
 * @property string|null $channel  instagram|sms|whatsapp
 */
class Conversation extends IdentifiableResource
{
    public static function type(): string
    {

        return 'conversation';
    }
}
