<?php


namespace nickdnk\Klaviyo\Resources\Shared;

/**
 * Per-channel send options of a campaign. Every channel variant carries the same single
 * field, so one class covers email, SMS and mobile push.
 *
 * @property bool|null $use_smart_sending
 */
class CampaignSendOptions extends Resource
{

    public function __construct(?bool $useSmartSending = null)
    {

        $this->use_smart_sending = $useSmartSending;
    }

}
