<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateWebFeed;
use nickdnk\Klaviyo\Resources\Request\UpdateWebFeed;
use nickdnk\Klaviyo\Resources\Response\WebFeed;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Services\Traits\HasCreate;
use nickdnk\Klaviyo\Services\Traits\HasDelete;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasList;
use nickdnk\Klaviyo\Services\Traits\HasUpdate;
use Psr\Http\Message\RequestInterface;

/**
 * Web feeds are external JSON or XML endpoints Klaviyo polls and makes available to
 * templates. The feed's `status` reports the health of that polling, so a feed that saved
 * cleanly can still report a refresh timeout later.
 */
class WebFeedService extends BaseService
{

    use HasCreate;
    use HasDelete;
    use HasGet;
    use HasList;
    use HasUpdate;

    /**
     * @link https://developers.klaviyo.com/en/reference/get_web_feeds
     * @return array{data: WebFeed[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function list(?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->listTrait($query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_web_feed
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): WebFeed|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/create_web_feed
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function create(CreateWebFeed $feed, ?Query $query = null, bool $returnRequest = false): WebFeed|RequestInterface
    {

        return $this->createTrait($feed, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/update_web_feed
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function update(UpdateWebFeed $feed, ?Query $query = null, bool $returnRequest = false): WebFeed|RequestInterface
    {

        return $this->updateTrait($feed, $query, $returnRequest);

    }

    protected function apiPath(): string
    {

        return 'web-feeds';
    }
}
