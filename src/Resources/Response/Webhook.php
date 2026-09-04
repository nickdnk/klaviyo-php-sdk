<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\Webhook as SharedWebhook;

/**
 * @property string      $name
 * @property string|null $description
 * @property string      $endpoint_url
 * @property bool        $enabled
 * @property string|null $created_at
 * @property string|null $updated_at
 */
class Webhook extends SharedWebhook
{

}
