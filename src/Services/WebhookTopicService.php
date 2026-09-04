<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Resources\Shared\WebhookTopic;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasList;
use Psr\Http\Message\RequestInterface;

/**
 * Topics a webhook can subscribe to. Read-only; ids are the values of
 * the constants on {@see \nickdnk\Klaviyo\Resources\Shared\WebhookTopic}.
 */
class WebhookTopicService extends BaseService
{

    use HasGet;
    use HasList;

    /**
     * @link https://developers.klaviyo.com/en/reference/get_webhook_topics
     * @return array{data: WebhookTopic[], links: ?PaginationLinks}|RequestInterface
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
     * @link https://developers.klaviyo.com/en/reference/get_webhook_topic
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): WebhookTopic|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    protected function apiPath(): string
    {

        return 'webhook-topics';
    }
}
