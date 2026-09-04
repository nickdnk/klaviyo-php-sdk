<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\WebFeed;

/**
 * POST /api/web-feeds: registers a URL Klaviyo polls and exposes to templates as feed data.
 * `name` must match `^[0-9_A-z]+$` (no hyphens or spaces). `status` reads back null until
 * Klaviyo has polled the feed once.
 *
 * @property string $name
 * @property string $url
 * @property string $request_method  get|post
 * @property string $content_type    json|xml
 */
class CreateWebFeed extends WebFeed
{

    public function __construct(string $name, string $url, string $requestMethod = 'get', string $contentType = 'json')
    {

        parent::__construct();
        $this->name = $name;
        $this->url = $url;
        $this->request_method = $requestMethod;
        $this->content_type = $contentType;
    }

}
