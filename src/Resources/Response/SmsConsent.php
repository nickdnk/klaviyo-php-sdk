<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\Resource;

/**
 * @property string      $consent  SUBSCRIBED|UNSUBSCRIBED|NEVER_SUBSCRIBED
 * @property string|null $consent_timestamp
 * @property bool|null   $can_receive_sms_marketing
 * @property bool|null   $can_receive_sms_transactional
 * @property string|null $method
 * @property string|null $method_detail
 * @property string|null $last_updated
 */
class SmsConsent extends Resource
{

}
