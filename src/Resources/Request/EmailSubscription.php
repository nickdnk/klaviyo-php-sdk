<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Resource;

/**
 * @property MarketingConsent $marketing
 */
class EmailSubscription extends Resource
{

    public function __construct(MarketingConsent $marketing)
    {
        $this->marketing = $marketing;
    }

}
