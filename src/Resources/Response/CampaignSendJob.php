<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\CampaignSendJob as SharedCampaignSendJob;

/**
 * @property string|null $status  cancelled|complete|processing|queued
 */
class CampaignSendJob extends SharedCampaignSendJob
{

}
