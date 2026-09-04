<?php


namespace nickdnk\Klaviyo\Resources\Shared;

class ConversationMessage extends IdentifiableResource
{
    public static function type(): string
    {

        return 'conversation-message';
    }
}
