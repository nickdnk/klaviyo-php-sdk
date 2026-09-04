<?php


namespace nickdnk\Klaviyo\Resources\Shared;

/**
 * `options` of a `static` {@see CampaignSendStrategy}: whether the datetime is read in each
 * recipient's own timezone, and what happens to recipients whose local time has passed.
 *
 * @property bool|null $is_local
 * @property bool|null $send_past_recipients_immediately
 */
class CampaignSendStrategyOptions extends Resource
{

    public function __construct(?bool $isLocal = null, ?bool $sendPastRecipientsImmediately = null)
    {

        $this->is_local = $isLocal;
        $this->send_past_recipients_immediately = $sendPastRecipientsImmediately;
    }

}
