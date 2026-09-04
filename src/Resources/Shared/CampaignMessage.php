<?php


namespace nickdnk\Klaviyo\Resources\Shared;

class CampaignMessage extends IdentifiableResource
{
    public static function type(): string
    {

        return 'campaign-message';
    }
}
