<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\CampaignMessage;
use nickdnk\Klaviyo\Resources\Shared\Relationship;
use nickdnk\Klaviyo\Resources\Shared\Template;

/**
 * POST /api/campaign-message-assign-template. The resource id is the message; the `template`
 * relationship names the reusable template to copy. Klaviyo snapshots the template into a
 * non-reusable version owned by the message and answers with the updated message.
 */
class AssignTemplateToCampaignMessage extends CampaignMessage
{

    public function __construct(string $messageId, string $templateId)
    {

        parent::__construct($messageId);
        $this->addRelationship('template', new Relationship(new Template($templateId)));
    }

}
