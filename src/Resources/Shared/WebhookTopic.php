<?php


namespace nickdnk\Klaviyo\Resources\Shared;

/**
 * A webhook topic: what a webhook subscribes to. Topics are a real, account-specific API resource
 * (`GET /api/webhook-topics`), not a fixed list: every metric on the account is a topic
 * (`event:api.<metric>`, `event:shopify.<metric>`, …) next to Klaviyo's own system topics
 * (`event:klaviyo.*`). The id is the topic string; there are no attributes.
 *
 * The system topics documented by Klaviyo are available as constants for readable comparisons
 * (`$topic->is(WebhookTopic::OPENED_EMAIL)`); anything else is compared by id string. The slug
 * Klaviyo derives from a metric name is not documented, so metric topics should be taken from
 * `webhookTopics.list()` rather than built by hand.
 *
 * @link https://developers.klaviyo.com/en/docs/working_with_system_webhooks#common-webhook-topics
 */
class WebhookTopic extends IdentifiableResource
{

    public const string BOUNCED_EMAIL                                 = 'event:klaviyo.bounced_email';
    public const string CLICKED_EMAIL                                 = 'event:klaviyo.clicked_email';
    public const string DROPPED_EMAIL                                 = 'event:klaviyo.dropped_email';
    public const string SUBSCRIBED_TO_EMAIL_MARKETING                 = 'event:klaviyo.subscribed_to_email_marketing';
    public const string UNSUBSCRIBED_FROM_EMAIL_MARKETING             = 'event:klaviyo.unsubscribed_from_email_marketing';
    public const string MANUALLY_SUPPRESSED_FROM_EMAIL_MARKETING      = 'event:klaviyo.manually_suppressed_from_email_marketing';
    public const string MANUALLY_UNSUPPRESSED_FROM_EMAIL_MARKETING    = 'event:klaviyo.manually_unsuppressed_from_email_marketing';
    public const string RECEIVED_EMAIL                                = 'event:klaviyo.received_email';
    public const string MARKED_EMAIL_AS_SPAM                          = 'event:klaviyo.marked_email_as_spam';
    public const string OPENED_EMAIL                                  = 'event:klaviyo.opened_email';
    public const string UPDATED_EMAIL_PREFERENCES                     = 'event:klaviyo.updated_email_preferences';
    public const string CLICKED_SMS                                   = 'event:klaviyo.clicked_sms';
    public const string FAILED_TO_DELIVER_AUTOMATED_RESPONSE_SMS      = 'event:klaviyo.failed_to_deliver_automated_response_sms';
    public const string FAILED_TO_DELIVER_SMS                         = 'event:klaviyo.failed_to_deliver_sms';
    public const string RECEIVED_AUTOMATED_RESPONSE_SMS               = 'event:klaviyo.received_automated_response_sms';
    public const string RECEIVED_SMS                                  = 'event:klaviyo.received_sms';
    public const string SENT_SMS                                      = 'event:klaviyo.sent_sms';
    public const string SUBSCRIBED_TO_SMS_MARKETING                   = 'event:klaviyo.subscribed_to_sms_marketing';
    public const string UNSUBSCRIBED_FROM_SMS_MARKETING               = 'event:klaviyo.unsubscribed_from_sms_marketing';
    public const string RECEIVED_PUSH                                 = 'event:klaviyo.received_push';
    public const string BOUNCED_PUSH                                  = 'event:klaviyo.bounced_push';
    public const string OPENED_PUSH                                   = 'event:klaviyo.opened_push';
    public const string READY_TO_REVIEW                               = 'event:klaviyo.ready_to_review';
    public const string SUBMITTED_RATING                              = 'event:klaviyo.submitted_rating';
    public const string SUBMITTED_REVIEW                              = 'event:klaviyo.submitted_review';

    public static function type(): string
    {

        return 'webhook-topic';
    }

    /**
     * Identifier for a topic string, e.g. `WebhookTopic::of('event:api.viewed_product')`.
     */
    public static function of(string $id): static
    {

        return new static($id);
    }

    /**
     * True when this topic is `$topic` (a constant, another topic id string, or a WebhookTopic).
     */
    public function is(self|string $topic): bool
    {

        return $this->id !== null && $this->id === ($topic instanceof self ? $topic->id : $topic);
    }

    /**
     * The integration segment of the id: `klaviyo` for system topics, `api` for metrics tracked
     * through the API, otherwise the integration key (`shopify`, …). Null when the id is not of the
     * form `event:<integration>.<slug>`.
     */
    public function integration(): ?string
    {

        return self::parts($this->id)[0] ?? null;
    }

    /**
     * The slug after the integration: `opened_email`, `viewed_product`, `placed_order`, …
     */
    public function slug(): ?string
    {

        return self::parts($this->id)[1] ?? null;
    }

    /**
     * True for Klaviyo's own `event:klaviyo.*` topics (the ones with constants here).
     */
    public function isSystemTopic(): bool
    {

        return $this->integration() === 'klaviyo';
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function parts(?string $id): ?array
    {

        if ($id === null || !preg_match('/^event:([^.]+)\.(.+)$/', $id, $m)) {
            return null;
        }

        return [$m[1], $m[2]];
    }

}
