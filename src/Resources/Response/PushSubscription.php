<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\Resource;

/**
 * @property PushMarketingConsent|null $marketing
 */
class PushSubscription extends Resource
{

    protected static function nested(): array
    {

        return ['marketing' => PushMarketingConsent::class];
    }
}
