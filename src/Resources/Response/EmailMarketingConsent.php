<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\Resource;

/**
 * @property string      $consent  SUBSCRIBED|UNSUBSCRIBED|NEVER_SUBSCRIBED
 * @property string|null $consent_timestamp
 * @property bool|null   $can_receive_email_marketing
 * @property bool|null   $double_optin
 * @property string|null $method
 * @property string|null $method_detail
 * @property string|null $custom_method_detail
 * @property string|null $last_updated
 * @property array|null  $suppression
 * @property array|null  $list_suppressions
 */
class EmailMarketingConsent extends Resource
{

}
