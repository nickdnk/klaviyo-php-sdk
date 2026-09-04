<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Resource;

/**
 * @property EmailSubscription|null $email
 * @property SmsSubscription|null $sms
 * @property WhatsappSubscription|null $whatsapp
 * @property PushSubscription|null $push
 */
class SubscriptionChannels extends Resource
{

    public function __construct(
        ?EmailSubscription    $email = null,
        ?SmsSubscription      $sms = null,
        ?WhatsappSubscription $whatsapp = null,
        ?PushSubscription     $push = null,
    )
    {
        $this->email = $email;
        $this->sms = $sms;
        $this->whatsapp = $whatsapp;
        $this->push = $push;
    }

}
