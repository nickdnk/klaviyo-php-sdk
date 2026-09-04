<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\Resource;

/**
 * @property EmailMarketingConsent|null $marketing
 */
class EmailSubscription extends Resource
{

    protected static function nested(): array
    {

        return ['marketing' => EmailMarketingConsent::class];
    }
}
