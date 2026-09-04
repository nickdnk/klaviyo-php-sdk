<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\WebFeed as SharedWebFeed;

/**
 * @property string|null $name
 * @property string|null $url
 * @property string|null $request_method  get|post
 * @property string|null $content_type    json|xml
 * @property string|null $created
 * @property string|null $updated
 * @property string|null $status          critical_nightly_refresh_timeout|disabled|ok|refreshing|warning_nightly_refresh_timeout|warning_periodic_refresh_timeout
 */
class WebFeed extends SharedWebFeed
{

}
