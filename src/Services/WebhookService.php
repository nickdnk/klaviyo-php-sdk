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

/**
 * Webhooks are HTTP callbacks Klaviyo posts events to. A subscription names its topics
 * ({@see WebhookTopicService}) and carries the secret its deliveries are signed with, which
 * {@see \nickdnk\Klaviyo\APIClient::parseWebhookRequest()} verifies.
 *
 * - Access is gated to Advanced KDP accounts and to OAuth apps Klaviyo has allowlisted; anything
 *   else answers 403. A webhook created by an OAuth app is scoped to that app.
 * - The subscribed topics add their own scope requirements: the event topics need `events:read`.
 * - `endpoint_url` comes back masked as `https://host/*****`, and the secret is never returned.
 * - Deliveries are account-wide and batched, so one callback can carry events for several
 *   profiles.
 *
 * @link https://developers.klaviyo.com/en/reference/webhooks_api_overview
 */
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
