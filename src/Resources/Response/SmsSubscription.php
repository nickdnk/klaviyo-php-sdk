<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\Resource;

/**
 * @property SmsConsent|null $marketing
 * @property SmsConsent|null $transactional
 */
class SmsSubscription extends Resource
{

    protected static function nested(): array
    {

        return [
            'marketing'     => SmsConsent::class,
            'transactional' => SmsConsent::class,
        ];
    }
}
