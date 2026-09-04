<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\Resource;

/**
 * @property string      $consent  SUBSCRIBED|UNSUBSCRIBED|NEVER_SUBSCRIBED
 * @property string|null $consent_timestamp
 * @property bool|null   $can_receive
 * @property string|null $phone_number
 * @property string|null $created_timestamp
 * @property string|null $last_updated
 * @property array|null  $metadata
 * @property string|null $valid_until
 */
class WhatsappConsent extends Resource
{

}
