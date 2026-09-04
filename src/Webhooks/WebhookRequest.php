<?php


namespace nickdnk\Klaviyo\Webhooks;

use nickdnk\Klaviyo\Resources\Response\Event;
use nickdnk\Klaviyo\Resources\Shared\IdentifiableResource;
use nickdnk\Klaviyo\Resources\Shared\WebhookTopic;

readonly class WebhookRequest
{

    /**
     * Constructed by {@see \nickdnk\Klaviyo\APIClient::parseWebhookRequest()} only after the HMAC
     * signature and freshness checks have passed.
     *
     * @param string $webhookId  The ID of the webhook that delivered the request, from meta.klaviyo_webhook_id
     * @param string $timestamp  Event timestamp from meta.timestamp (ISO 8601)
     * @param string $apiVersion The API version of the webhook, i.e. 2024-06-28.
     * @param list<array{topic: WebhookTopic, payload: Event|IdentifiableResource|array|null}> $events
     *                           One entry per event in the delivery (deliveries are batched and
     *                           account-wide). Compare topics with `$topic->is(WebhookTopic::…)`.
     */
    public function __construct(public string $webhookId, public string $timestamp, public string $apiVersion,
        public array $events
    )
    {
    }

    /**
     * Events whose topic is `$topic` (a WebhookTopic, one of its constants, or a topic id).
     *
     * @return list<array{topic: WebhookTopic, payload: Event|IdentifiableResource|array|null}>
     */
    public function eventsFor(WebhookTopic|string $topic): array
    {

        return array_values(array_filter($this->events, static fn(array $e) => $e['topic']->is($topic)));

    }

}
