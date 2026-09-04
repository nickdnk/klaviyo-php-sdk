<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Resource;

/**
 * @property string $consent  SUBSCRIBED|UNSUBSCRIBED
 * @property string|null $consented_at  ISO 8601 — only for historical imports
 */
class MarketingConsent extends Resource
{

    public function __construct(string $consent, ?string $consentedAt = null)
    {
        $this->consent = $consent;
        if ($consentedAt) {
            $this->consented_at = $consentedAt;
        }
    }

}
