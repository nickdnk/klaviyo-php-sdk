<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Relationship;
use nickdnk\Klaviyo\Resources\Shared\Webhook;
use nickdnk\Klaviyo\Resources\Shared\WebhookTopic;

/**
 * POST /api/webhooks. The `webhook-topics` relationship is required by the API; pass the topics
 * to subscribe to as {@see WebhookTopic} objects, its constants, or topic id strings from
 * `webhookTopics.list()`. Webhook endpoints are only available to OAuth-app tokens (403 with a
 * private API key).
 *
 * @property string      $name
 * @property string      $endpoint_url
 * @property string      $secret_key
 * @property string|null $description
 */
class CreateWebhook extends Webhook
{

    /**
     * @param list<WebhookTopic|string> $topics e.g. `[WebhookTopic::OPENED_EMAIL, 'event:api.viewed_product']`
     */
    public function __construct(string $name, string $endpointUrl, string $secretKey, array $topics = [], ?string $description = null)
    {
        parent::__construct();
        $this->name = $name;
        $this->endpoint_url = $endpointUrl;
        $this->secret_key = $secretKey;
        $this->description = $description;
        if ($topics) {
            $this->setTopics($topics);
        }
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
