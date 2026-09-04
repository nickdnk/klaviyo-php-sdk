<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\CampaignMessage as SharedCampaignMessage;
use nickdnk\Klaviyo\Resources\Shared\CampaignMessageDefinition;
use nickdnk\Klaviyo\Resources\Shared\Image;
use nickdnk\Klaviyo\Resources\Shared\Relationship;

/**
 * PATCH /api/campaign-messages/{id}. The definition replaces the message body wholesale, so
 * pass the full channel definition rather than the fields that changed.
 *
 * @property CampaignMessageDefinition|null $definition
 */
class UpdateCampaignMessage extends SharedCampaignMessage
{

    public function __construct(string $id, ?CampaignMessageDefinition $definition = null, ?string $imageId = null)
    {

        parent::__construct($id);
        $this->definition = $definition;
        if ($imageId !== null) {
            $this->addRelationship('image', new Relationship(new Image($imageId)));
        }
    }

}
