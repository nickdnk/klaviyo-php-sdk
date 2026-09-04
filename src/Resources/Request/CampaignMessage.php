<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\CampaignMessage as SharedCampaignMessage;
use nickdnk\Klaviyo\Resources\Shared\CampaignMessageDefinition;
use nickdnk\Klaviyo\Resources\Shared\Image;
use nickdnk\Klaviyo\Resources\Shared\Relationship;

/**
 * One entry of the `campaign-messages` attribute of {@see CreateCampaign}: the channel
 * definition plus the image the message renders, if any. Klaviyo assigns the id, so these
 * carry a definition only.
 *
 * @property CampaignMessageDefinition $definition
 */
class CampaignMessage extends SharedCampaignMessage
{

    public function __construct(CampaignMessageDefinition $definition, ?string $imageId = null)
    {

        parent::__construct();
        $this->definition = $definition;
        if ($imageId !== null) {
            $this->addRelationship('image', new Relationship(new Image($imageId)));
        }
    }

}
