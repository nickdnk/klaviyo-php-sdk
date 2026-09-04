<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\WebFeed;

/**
 * PATCH /api/web-feeds/{id}. Every attribute is optional; only the ones set are sent.
 *
 * @property string|null $name
 * @property string|null $url
 * @property string|null $request_method  get|post
 * @property string|null $content_type    json|xml
 */
class UpdateWebFeed extends WebFeed
{

    public function __construct(string $id)
    {

        parent::__construct($id);
    }

}
