<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\CampaignRecipientEstimationJob as SharedCampaignRecipientEstimationJob;

/**
 * POST /api/campaign-recipient-estimation-jobs: recounts the audience of the campaign whose
 * id this carries. The job that comes back is keyed by that same campaign id.
 */
class CampaignRecipientEstimationJob extends SharedCampaignRecipientEstimationJob
{

    public function __construct(string $campaignId)
    {

        parent::__construct($campaignId);
    }

}
