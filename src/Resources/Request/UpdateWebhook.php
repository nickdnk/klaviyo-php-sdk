<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Relationship;
use nickdnk\Klaviyo\Resources\Shared\Webhook;
use nickdnk\Klaviyo\Resources\Shared\WebhookTopic;

/**
 * @property string|null $name
 * @property string|null $endpoint_url
 * @property string|null $secret_key
 * @property string|null $description
 * @property bool|null $enabled
 */
class UpdateWebhook extends Webhook
{

    public function __construct(string $id)
    {
        parent::__construct($id);
    }

    /**
     * Replaces the topic relationship.
     *
     * @param list<WebhookTopic|string> $topics
     */
    public function setTopics(array $topics): static
    {
        $this->addRelationship('webhook-topics', new Relationship(array_map(
            static fn(WebhookTopic|string $t) => new WebhookTopic($t instanceof WebhookTopic ? $t->id : $t),
            array_values($topics),
        )));

        return $this;
    }

}
