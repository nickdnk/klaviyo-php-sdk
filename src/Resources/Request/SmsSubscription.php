<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Resource;

/**
 * @property MarketingConsent|null $marketing
 * @property MarketingConsent|null $transactional
 */
class SmsSubscription extends Resource
{

    public function __construct(?MarketingConsent $marketing = null, ?MarketingConsent $transactional = null)
    {
        $this->marketing = $marketing;
        $this->transactional = $transactional;
    }

}
