<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateBackInStockSubscription;
use nickdnk\Klaviyo\Services\Traits\HasCreate;
use Psr\Http\Message\RequestInterface;

/**
 * Back in stock notification signups against one catalog variant
 * ({@see CatalogVariantService}). Write-only: Klaviyo exposes no read endpoint for the
 * subscriptions an account holds, so a subscription is fire-and-forget once created.
 */
class BackInStockSubscriptionService extends BaseService
{

    use HasCreate;

    /**
     * Klaviyo answers 202 with an empty body. The profile is matched or created from the
     * identifiers on the subscription's profile.
     *
     * @link https://developers.klaviyo.com/en/reference/create_back_in_stock_subscription
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function create(CreateBackInStockSubscription $subscription, ?Query $query = null, bool $returnRequest = false): ?RequestInterface
    {

        return $this->createTrait($subscription, $query, $returnRequest);

    }

    protected function apiPath(): string
    {

        return 'back-in-stock-subscriptions';

    }

}
