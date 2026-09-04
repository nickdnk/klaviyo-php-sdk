<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\CampaignSendJob as SharedCampaignSendJob;

/**
 * POST /api/campaign-send-jobs: triggers the send of the campaign whose id this carries.
 * The job that comes back is keyed by that same campaign id.
 */
class CampaignSendJob extends SharedCampaignSendJob
{

    public function __construct(string $campaignId)
    {

        parent::__construct($campaignId);
    }

}
