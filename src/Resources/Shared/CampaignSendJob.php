<?php


namespace nickdnk\Klaviyo\Resources\Shared;

class CampaignSendJob extends IdentifiableResource
{
    public static function type(): string
    {

        return 'campaign-send-job';
    }
}
