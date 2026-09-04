<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\WebhookTopic as SharedWebhookTopic;

/**
 * What `webhookTopics.list()` / `get()` and a webhook's `webhook-topics` relationship hydrate to.
 * Topics carry no attributes, so this only gives the type its place in the Response namespace;
 * the constants and helpers live on the shared class.
 */
class WebhookTopic extends SharedWebhookTopic
{
}
