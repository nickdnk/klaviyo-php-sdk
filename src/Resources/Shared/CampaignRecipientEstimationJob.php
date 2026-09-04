<?php


namespace nickdnk\Klaviyo\Resources\Shared;

class CampaignRecipientEstimationJob extends IdentifiableResource
{
    public static function type(): string
    {

        return 'campaign-recipient-estimation-job';
    }
}
