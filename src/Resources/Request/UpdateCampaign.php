<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Campaign;
use nickdnk\Klaviyo\Resources\Shared\CampaignAudiences;
use nickdnk\Klaviyo\Resources\Shared\CampaignSendOptions;
use nickdnk\Klaviyo\Resources\Shared\CampaignSendStrategy;
use nickdnk\Klaviyo\Resources\Shared\CampaignTrackingOptions;

/**
 * PATCH /api/campaigns/{id}. Set only the attributes to change; the messages of a campaign
 * are edited through {@see UpdateCampaignMessage} instead.
 *
 * @property string|null                  $name
 * @property CampaignAudiences|null       $audiences
 * @property CampaignSendOptions|null     $send_options
 * @property CampaignTrackingOptions|null $tracking_options
 * @property CampaignSendStrategy|null    $send_strategy
 */
class UpdateCampaign extends Campaign
{

    public function __construct(string $id)
    {

        parent::__construct($id);
    }

}
