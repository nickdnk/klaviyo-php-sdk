<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\Resource;

/**
 * @property EmailSubscription|null    $email
 * @property SmsSubscription|null      $sms
 * @property WhatsappSubscription|null $whatsapp
 * @property PushSubscription|null     $mobile_push
 */
class SubscriptionChannels extends Resource
{

    protected static function nested(): array
    {

        return [
            'email'       => EmailSubscription::class,
            'sms'         => SmsSubscription::class,
            'whatsapp'    => WhatsappSubscription::class,
            'mobile_push' => PushSubscription::class,
        ];
    }
}
