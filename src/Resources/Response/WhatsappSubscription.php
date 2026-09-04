<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\Resource;

/**
 * @property WhatsappConsent|null $marketing
 * @property WhatsappConsent|null $transactional
 * @property WhatsappConsent|null $conversational
 */
class WhatsappSubscription extends Resource
{

    protected static function nested(): array
    {

        return [
            'marketing'      => WhatsappConsent::class,
            'transactional'  => WhatsappConsent::class,
            'conversational' => WhatsappConsent::class,
        ];
    }
}
