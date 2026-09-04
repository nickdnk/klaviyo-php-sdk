<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\Resource;

/**
 * @property string      $consent  SUBSCRIBED|UNSUBSCRIBED|NEVER_SUBSCRIBED
 * @property string|null $consent_timestamp
 * @property bool|null   $can_receive_push_marketing
 */
class PushMarketingConsent extends Resource
{

}
