<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\Campaign as SharedCampaign;
use nickdnk\Klaviyo\Resources\Shared\CampaignAudiences;
use nickdnk\Klaviyo\Resources\Shared\CampaignSendOptions;
use nickdnk\Klaviyo\Resources\Shared\CampaignSendStrategy;
use nickdnk\Klaviyo\Resources\Shared\CampaignTrackingOptions;

/**
 * @property string|null                  $name
 * @property string|null                  $status            Adding Recipients|Cancelled|Cancelled: Account Disabled|Cancelled: Billing Limit|Cancelled: Internal Error|Cancelled: Misconfigured|Cancelled: No Recipients|Cancelled: Smart Sending|Draft|Preparing to schedule|Preparing to send|Queued without Recipients|Scheduled|Sending|Sending Segments|Sent|Unknown|Variations Sent
 * @property bool|null                    $archived
 * @property CampaignAudiences|null       $audiences
 * @property CampaignSendOptions|null     $send_options
 * @property CampaignTrackingOptions|null $tracking_options
 * @property CampaignSendStrategy|null    $send_strategy
 * @property string|null                  $created_at
 * @property string|null                  $scheduled_at
 * @property string|null                  $updated_at
 * @property string|null                  $send_time
 */
class Campaign extends SharedCampaign
{

    protected static function nested(): array
    {

        return [
            'audiences'        => CampaignAudiences::class,
            'send_options'     => CampaignSendOptions::class,
            'tracking_options' => CampaignTrackingOptions::class,
            'send_strategy'    => CampaignSendStrategy::class,
        ];
    }

}
