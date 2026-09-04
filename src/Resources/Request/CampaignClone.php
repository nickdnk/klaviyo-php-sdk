<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Campaign;

/**
 * POST /api/campaign-clone. The resource id is the campaign being copied; Klaviyo answers
 * with the new campaign. Leaving `new_name` unset lets Klaviyo derive the copy's name.
 *
 * @property string|null $new_name
 */
class CampaignClone extends Campaign
{

    public function __construct(string $campaignId, ?string $newName = null)
    {

        parent::__construct($campaignId);
        $this->new_name = $newName;
    }

}
