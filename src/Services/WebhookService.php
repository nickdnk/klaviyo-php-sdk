<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateWebhook;
use nickdnk\Klaviyo\Resources\Request\UpdateWebhook;
use nickdnk\Klaviyo\Resources\Response\Webhook;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Services\Traits\HasCreate;
use nickdnk\Klaviyo\Services\Traits\HasDelete;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasList;
use nickdnk\Klaviyo\Services\Traits\HasUpdate;
use Psr\Http\Message\RequestInterface;

class WebhookService extends BaseService
{
    use HasCreate;
    use HasDelete;
    use HasGet;
    use HasList;
    use HasUpdate;

    /**
     * @link https://developers.klaviyo.com/en/reference/get_webhooks
     * @return array{data: Webhook[], links: ?PaginationLinks}|RequestInterface
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
     * @link https://developers.klaviyo.com/en/reference/get_webhook
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): Webhook|RequestInterface|null
    {
        return $this->getTrait($id, $query, $returnRequest);
    }

    /**
     * @link https://developers.klaviyo.com/en/reference/create_webhook
     *
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function create(CreateWebhook $webhook, ?Query $query = null, bool $returnRequest = false): Webhook|RequestInterface
    {
        return $this->createTrait($webhook, $query, $returnRequest);
    }

    /**
     * @link https://developers.klaviyo.com/en/reference/update_webhook
     *
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function update(UpdateWebhook $webhook, ?Query $query = null, bool $returnRequest = false): Webhook|RequestInterface
    {
        return $this->updateTrait($webhook, $query, $returnRequest);
    }

    protected function apiPath(): string
    {
        return 'webhooks';
    }
}
