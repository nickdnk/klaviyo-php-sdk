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
 * The topics a webhook can subscribe to. Read-only.
 *
 * - The set is open-ended: alongside the `event:klaviyo.*` system topics named by the constants
 *   on {@see \nickdnk\Klaviyo\Resources\Shared\WebhookTopic}, an account has an
 *   `event:<integration>.<metric>` topic for every metric it has ever seen.
 * - Access is gated exactly as {@see WebhookService} is.
 *
 * @link https://developers.klaviyo.com/en/reference/webhooks_api_overview
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
