<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\CampaignSendJob;

/**
 * PATCH /api/campaign-send-jobs/{id}. `cancel` permanently cancels the send and leaves the
 * campaign in status Cancelled; `revert` stops it and returns the campaign to Draft.
 *
 * @property string $action  cancel|revert
 */
class CancelCampaignSend extends CampaignSendJob
{

    public const string ACTION_CANCEL = 'cancel';
    public const string ACTION_REVERT = 'revert';

    public function __construct(string $sendJobId, string $action = self::ACTION_CANCEL)
    {

        parent::__construct($sendJobId);
        $this->action = $action;
    }

}
