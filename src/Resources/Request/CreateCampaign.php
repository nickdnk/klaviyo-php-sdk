<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Campaign;
use nickdnk\Klaviyo\Resources\Shared\CampaignAudiences;
use nickdnk\Klaviyo\Resources\Shared\CampaignSendOptions;
use nickdnk\Klaviyo\Resources\Shared\CampaignSendStrategy;
use nickdnk\Klaviyo\Resources\Shared\CampaignTrackingOptions;

/**
 * POST /api/campaigns. The messages ride along as the `campaign-messages` attribute — a
 * `{data: [...]}` envelope nested inside `attributes`, not a JSON:API relationship — one per
 * channel the campaign sends on.
 *
 * @property string                       $name
 * @property CampaignAudiences            $audiences
 * @property CampaignSendStrategy|null    $send_strategy  defaults to an immediate send
 * @property CampaignSendOptions|null     $send_options
 * @property CampaignTrackingOptions|null $tracking_options
 */
class CreateCampaign extends Campaign
{

    /**
     * @param CampaignMessage[] $messages
     */
    public function __construct(string $name, CampaignAudiences $audiences, array $messages)
    {

        parent::__construct();
        $this->name = $name;
        $this->audiences = $audiences;
        $this->{'campaign-messages'} = CampaignMessage::wrapDataMany(array_values($messages));
    }

}
